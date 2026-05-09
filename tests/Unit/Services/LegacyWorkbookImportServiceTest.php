<?php

use App\Services\LegacyWorkbookImportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

function invokeLegacyPrivate(LegacyWorkbookImportService $service, string $method, array $args = []): mixed
{
    $reflection = new ReflectionClass($service);
    $target = $reflection->getMethod($method);
    $target->setAccessible(true);

    return $target->invokeArgs($service, $args);
}

test('legacy layout detection supports cyrillic headers and shifted rows', function () {
    $service = app(LegacyWorkbookImportService::class);

    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray([
        ['metadata row'],
        ['Зав. №.', 'Доп. Инф.', 'Произв.', 'Тегло', 'PT', 'PD', 'RH'],
        ['SER-1', 'desc', 'BMW', 1.2, 100, 200, 10],
    ], null, 'A1');

    $layout = invokeLegacyPrivate($service, 'detectLayout', [$sheet]);

    expect($layout['start_row'])->toBe(3)
        ->and($layout['serial'])->toBe(1)
        ->and($layout['details'])->toBe(2)
        ->and($layout['model'])->toBe(3)
        ->and($layout['weight'])->toBe(4)
        ->and($layout['pt'])->toBe(5)
        ->and($layout['pd'])->toBe(6)
        ->and($layout['rh'])->toBe(7);
});

test('legacy invalid issue detection flags ambiguous assay values', function () {
    $service = app(LegacyWorkbookImportService::class);

    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->fromArray([
        ['Зав. №.', 'Доп. Инф.', 'Произв.', 'Тегло', 'PT', 'PD', 'RH'],
        ['SER-AMB-1', 'desc', 'BMW', 1.1, '0/0', '0,16/fe', 300],
    ], null, 'A1');

    $layout = invokeLegacyPrivate($service, 'detectLayout', [$sheet]);
    $mapped = invokeLegacyPrivate($service, 'mapRow', [$sheet, 2, $layout, 'BMW']);
    $issue = invokeLegacyPrivate($service, 'determineInvalidIssue', [$sheet, 2, $layout, $mapped]);

    expect($issue)->toBe('ambiguous_assay_value');
});

test('legacy serial normalization and assay signature stay stable across formats', function () {
    $service = app(LegacyWorkbookImportService::class);

    $a = invokeLegacyPrivate($service, 'normalizeSerial', ['AB 12-34/56.']);
    $b = invokeLegacyPrivate($service, 'normalizeSerial', ['ab123456']);

    expect($a)->toBe($b);

    $signatureA = invokeLegacyPrivate($service, 'signature', [
        'group-1',
        $a,
        [
            'weight_kg' => 1.234,
            'pt_ppm' => 100.0,
            'pd_ppm' => 200.0,
            'rh_ppm' => 10.0,
        ],
    ]);

    $signatureB = invokeLegacyPrivate($service, 'signature', [
        'group-1',
        $b,
        [
            'weight_kg' => 1.2340,
            'pt_ppm' => 100,
            'pd_ppm' => 200,
            'rh_ppm' => 10,
        ],
    ]);

    expect($signatureA)->toBe($signatureB);
});
