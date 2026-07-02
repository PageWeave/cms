<?php

declare(strict_types=1);

final class VersionTest extends PwTestCase
{
    public function test_pw_version_matches_version_file(): void
    {
        $expected = trim((string) file_get_contents(__DIR__ . '/../VERSION'));
        $this->assertSame($expected, pw_version());
    }

    public function test_pw_version_is_not_dev_in_repo(): void
    {
        // Guards against the VERSION read silently failing.
        $this->assertNotSame('dev', pw_version());
    }

    public function test_pw_version_is_semver(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', pw_version());
    }
}
