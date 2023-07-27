<?php

namespace LSNepomuceno\LaravelA1PdfSign\Tests\Sign\ValidatePdfSignature;

use LSNepomuceno\LaravelA1PdfSign\Sign\ValidatePdfSignature;
use LSNepomuceno\LaravelA1PdfSign\Tests\TestCase;
use ReflectionMethod;

class ProcessDataInfoFunctionTest extends TestCase
{

    public static function stringsDataProvider()
    {
        /** info of cert from SAT (México) certificates for Testing porpouse */
        $withoutCommaInContentPath = __DIR__ . '/../../Resources/CertInfoExamples/without-comma.txt';
        $withoutCommasInContentContent = trim(file_get_contents($withoutCommaInContentPath));
        $withoutCommaInContentExpectedPath = __DIR__ . '/../../Resources/CertInfoExamples/without-comma.json';
        $withoutCommaInContentExpectedArr = json_decode(file_get_contents($withoutCommaInContentExpectedPath), true);

        $withCommaInContentPath = __DIR__ . '/../../Resources/CertInfoExamples/with-comma.txt';
        $withCommasInContentContent = trim(file_get_contents($withCommaInContentPath));
        $withCommaInContentExpectedPath = __DIR__ . '/../../Resources/CertInfoExamples/with-comma.json';
        $withCommaInContentExpectedArr = json_decode(file_get_contents($withCommaInContentExpectedPath), true);
        return [
            [$withCommaInContentExpectedArr, $withCommasInContentContent],
            [$withoutCommaInContentExpectedArr, $withoutCommasInContentContent],
        ];
    }

    /**
     * 
     * @dataProvider stringsDataProvider
     * 
     * @param array<string, array<string, mixed>> $expectedResponse
     * 
     * @return void 
     */
    public function testProcessDataToInfoFunction(array $expectedResponse, string $content)
    {
        $method = new ReflectionMethod(
            '\LSNepomuceno\LaravelA1PdfSign\Sign\ValidatePdfSignature',
            'processDataToInfo'
        );
        $method->setAccessible(true);
    
        $data = $method->invoke(new ValidatePdfSignature(), $content);
        $this->assertSame($expectedResponse, $data);
    }
}
