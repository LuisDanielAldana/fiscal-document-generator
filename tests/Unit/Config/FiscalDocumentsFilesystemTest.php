<?php

test('fiscal documents use a private local disk by default', function () {
    $disk = config('filesystems.disks.fiscal-documents');

    expect($disk['driver'])->toBe('local')
        ->and($disk['root'])->toBe(storage_path('app/private/fiscal-documents'))
        ->and($disk['visibility'])->toBe('private')
        ->and($disk['throw'])->toBeTrue();
});
