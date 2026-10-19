<?php

declare(strict_types=1);

describe('StockForkLaw', function (): void {
    it('opens no transaction of its own on the checkout path', function (string $path): void {
        $source = file_get_contents(dirname(__DIR__, 2).'/'.$path);

        expect($source)->not->toContain('DB::transaction')
            ->and($source)->not->toContain('beginTransaction');
    })->with([
        'src/Actions/CompleteCartAction.php',
        'vendor/shopper/cart/src/Actions/CreateOrderFromCartAction.php',
    ]);
});
