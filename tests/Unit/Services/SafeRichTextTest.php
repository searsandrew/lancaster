<?php

use App\Services\SafeRichText;

test('it keeps supported formatting and removes unsafe markup and attributes', function () {
    $html = '<p onclick="alert(1)"><strong>Key point</strong><script>alert(2)</script></p><a href="javascript:alert(3)">Link</a>';

    expect(SafeRichText::sanitize($html))
        ->toBe('<p><strong>Key point</strong>alert(2)</p>Link');
});
