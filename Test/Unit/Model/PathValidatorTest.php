<?php
declare(strict_types=1);

namespace MageOS\PageBuilderTemplateImportExport\Test\Unit\Model;

use MageOS\PageBuilderTemplateImportExport\Model\PathValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PathValidatorTest extends TestCase
{
    /**
     * @var PathValidator
     */
    private PathValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new PathValidator();
    }

    #[DataProvider('traversalPathProvider')]
    public function testHasTraversal(string $path, bool $expected): void
    {
        $this->assertSame($expected, $this->validator->hasTraversal($path));
    }

    /**
     * @return array
     */
    public static function traversalPathProvider(): array
    {
        return [
            'plain relative' => ['wysiwyg/image.jpg', false],
            'leading slash' => ['/media/wysiwyg/image.jpg', false],
            'dot component' => ['./wysiwyg/image.jpg', false],
            'double-dot filename is not traversal' => ['wysiwyg/..image.jpg', false],
            'parent traversal' => ['../../app/etc/env.php', true],
            'embedded traversal' => ['wysiwyg/../../../app/etc/env.php', true],
            'backslash traversal' => ['..\\..\\app\\etc\\env.php', true],
            'nul byte' => ["wysiwyg/image.jpg\0.png", true],
        ];
    }

    #[DataProvider('relativePathProvider')]
    public function testIsSafeRelativePath(string $path, bool $expected): void
    {
        $this->assertSame($expected, $this->validator->isSafeRelativePath($path));
    }

    /**
     * @return array
     */
    public static function relativePathProvider(): array
    {
        return [
            'plain relative' => ['wysiwyg/image.jpg', true],
            'nested relative' => ['wysiwyg/sub/dir/image.jpg', true],
            'empty' => ['', false],
            'parent traversal' => ['../../app/etc/env.php', false],
            'backslash traversal' => ['..\\..\\app\\etc\\env.php', false],
            'absolute path' => ['/etc/passwd', false],
            'backslash absolute' => ['\\etc\\passwd', false],
            'scheme prefix' => ['phar://evil.zip/x', false],
            'drive letter' => ['C:/windows/win.ini', false],
            'nul byte' => ["wysiwyg/image.jpg\0.png", false],
        ];
    }
}
