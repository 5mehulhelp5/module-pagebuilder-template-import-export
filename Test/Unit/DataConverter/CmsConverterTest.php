<?php
declare(strict_types=1);

namespace MageOS\PageBuilderTemplateImportExport\Test\Unit\DataConverter;

use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Data\Wysiwyg\Normalizer;
use Magento\Framework\Filter\Template\Tokenizer\Parameter;
use Magento\Framework\Filter\Template\Tokenizer\ParameterFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Serialize\Serializer\Serialize;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\PageBuilderTemplateImportExport\DataConverter\CmsConverter;
use MageOS\PageBuilderTemplateImportExport\Model\PathValidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CmsConverterTest extends TestCase
{
    /**
     * @var CmsConverter
     */
    private CmsConverter $converter;

    /**
     * @var ManagerInterface|MockObject
     */
    private ManagerInterface|MockObject $messageManager;

    protected function setUp(): void
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://example.com/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $this->messageManager = $this->createMock(ManagerInterface::class);

        $this->converter = new CmsConverter(
            $this->createMock(Normalizer::class),
            $this->createMock(ParameterFactory::class),
            $this->createMock(Json::class),
            $this->createMock(BlockRepositoryInterface::class),
            $this->createMock(Serialize::class),
            $storeManager,
            $this->messageManager,
            $this->createMock(DeploymentConfig::class),
            new PathValidator()
        );
    }

    public function testTraversalMediaDirectiveIsSkippedWithWarning(): void
    {
        $this->messageManager->expects($this->once())->method('addWarningMessage');

        $result = $this->converter->convert(
            'a {{media url=wysiwyg/ok.jpg}} b {{media url=../../../app/etc/env.php}} c'
        );

        $this->assertSame(['/media/wysiwyg/ok.jpg'], $result['assets']);
    }

    #[DataProvider('unsafeMediaUrlProvider')]
    public function testUnsafeMediaDirectiveYieldsNoAsset(string $url): void
    {
        $result = $this->converter->convert('x {{media url=' . $url . '}} y');

        $this->assertSame([], $result['assets']);
    }

    /**
     * @return array
     */
    public static function unsafeMediaUrlProvider(): array
    {
        return [
            'parent traversal' => ['../../../app/etc/env.php'],
            'backslash traversal' => ['..\\..\\app\\etc\\env.php'],
            'absolute path' => ['/etc/passwd'],
            'scheme prefix' => ['phar://evil.zip/x'],
            'drive letter' => ['C:/windows/win.ini'],
        ];
    }

    public function testLegitimateMediaDirectivesAreCollected(): void
    {
        $this->messageManager->expects($this->never())->method('addWarningMessage');

        $result = $this->converter->convert(
            'a {{media url=wysiwyg/ok.jpg}} b {{media url="wysiwyg/quoted.png"}} c'
        );

        $this->assertSame(
            ['/media/wysiwyg/ok.jpg', '/media/wysiwyg/quoted.png'],
            $result['assets']
        );
    }

    #[DataProvider('unsafeWidgetUrlProvider')]
    public function testWidgetParamAssetOutsideMediaIsNotCollected(string $url): void
    {
        $converter = $this->makeConverterWithWidgetParam(
            'conditions_encoded',
            '[{"link":"' . $url . '"}]'
        );

        $result = $converter->convert('{{widget type="Foo" conditions_encoded="x"}}');

        $this->assertSame([], $result['assets']);
    }

    /**
     * @return array
     */
    public static function unsafeWidgetUrlProvider(): array
    {
        return [
            'traversal out of media' => ['https://example.com/media/../../../app/etc/env.php'],
            'absolute non-media path' => ['https://example.com/etc/passwd'],
            'static asset (not media)' => ['https://example.com/static/version1/x.js'],
        ];
    }

    public function testWidgetParamMediaAssetIsCollected(): void
    {
        $converter = $this->makeConverterWithWidgetParam(
            'conditions_encoded',
            '[{"link":"https://example.com/media/wysiwyg/ok.jpg"}]'
        );

        $result = $converter->convert('{{widget type="Foo" conditions_encoded="x"}}');

        $this->assertSame(['/media/wysiwyg/ok.jpg'], $result['assets']);
    }

    /**
     * Build a converter whose widget tokenizer yields a single param, using
     * real serializers so the JSON unserialize/serialize path executes.
     *
     * @param string $key
     * @param string $jsonValue
     * @return CmsConverter
     */
    private function makeConverterWithWidgetParam(string $key, string $jsonValue): CmsConverter
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->willReturn('https://example.com/');
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $tokenizer = $this->createMock(Parameter::class);
        $tokenizer->method('tokenize')->willReturn([$key => $jsonValue]);
        $parameterFactory = $this->createMock(ParameterFactory::class);
        $parameterFactory->method('create')->willReturn($tokenizer);

        return new CmsConverter(
            new Normalizer(),
            $parameterFactory,
            new Json(),
            $this->createMock(BlockRepositoryInterface::class),
            new Serialize(),
            $storeManager,
            $this->messageManager,
            $this->createMock(DeploymentConfig::class),
            new PathValidator()
        );
    }
}
