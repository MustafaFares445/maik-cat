<?php

use App\Http\Requests\API\ItemFilterRequest;

it('accepts both supported text search parameter formats', function (array $query): void {
    $request = ItemFilterRequest::create('/api/items', 'GET', $query);
    $request->setContainer(app());
    $request->setRedirector(app('redirect'));

    $request->validateResolved();

    expect($request->input('text'))->toBe('GM10')
        ->and($request->input('filter.text'))->toBe('GM10');
})->with([
    'top-level text' => [['text' => 'GM10']],
    'nested filter text' => [['filter' => ['text' => 'GM10']]],
]);

it('uses the top-level text value when both formats are supplied', function (): void {
    $request = ItemFilterRequest::create('/api/items', 'GET', [
        'text' => 'GM10',
        'filter' => ['text' => 'OLD-VALUE'],
    ]);
    $request->setContainer(app());
    $request->setRedirector(app('redirect'));

    $request->validateResolved();

    expect($request->input('text'))->toBe('GM10')
        ->and($request->input('filter.text'))->toBe('GM10');
});
