<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

abstract class PwTestCase extends TestCase
{
    protected string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $base = getenv('PW_TEST_TMP') ?: sys_get_temp_dir();
        if (!is_dir($base)) {
            @mkdir($base, 0777, true);
        }
        $this->tmp = $base . '/pw_' . bin2hex(random_bytes(8));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->rm($this->tmp);
    }

    private function rm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->rm($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    protected function cmsDir(): string
    {
        return $this->tmp . '/_cms';
    }

    protected function webroot(): string
    {
        return $this->tmp . '/webroot';
    }
}
