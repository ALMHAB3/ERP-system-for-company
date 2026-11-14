<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/** Guards against the kind of mistake that blanks the whole interface for every user. */
final class InterfaceFilesTest extends TestCase
{
    public function test_every_english_phrase_entry_is_a_pair(): void
    {
        $src = file_get_contents(base_path('public/app/i18n-en.js'));
        $this->assertDoesNotMatchRegularExpression('/\],\s*,/', $src, 'An empty slot (",,") in the phrase list breaks the interface.');
        $this->assertDoesNotMatchRegularExpression('/\[\s*,/', $src);
        $this->assertSame(0, substr_count($src, "\n,\n"));
    }

    public function test_interface_javascript_has_no_syntax_errors(): void
    {
        if (trim((string) shell_exec('command -v node')) === '') {
            $this->markTestSkipped('node is not installed');
        }
        $files = array_merge(glob(base_path('public/app/*.js')) ?: [], glob(base_path('public/app/views/*.js')) ?: []);
        $this->assertNotEmpty($files);
        foreach ($files as $f) {
            $tmp = sys_get_temp_dir().'/chk-'.md5($f).'.mjs';
            copy($f, $tmp);
            exec('node --check '.escapeshellarg($tmp).' 2>&1', $out, $code);
            @unlink($tmp);
            $this->assertSame(0, $code, basename($f).': '.implode("\n", $out));
        }
    }
}
