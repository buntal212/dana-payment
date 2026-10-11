<?php

namespace Tests\Unit;

use App\Services\DanaSnapSignatureService;
use PHPUnit\Framework\TestCase;

class DanaSnapSignatureServiceTest extends TestCase
{
    public function test_it_minifies_json_without_rewriting_string_tokens(): void
    {
        $json = "{\n  \"message\" : \"space stays here\",\n  \"path\" : \"a\\/b\"\n}";

        $minified = (new DanaSnapSignatureService)->minifyJson($json);

        $this->assertSame('{"message":"space stays here","path":"a\\/b"}', $minified);
    }
}
