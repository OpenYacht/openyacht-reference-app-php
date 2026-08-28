<?php

use App\Services\Federation\RichTextSanitizer;

function sanitize(string $html): string
{
    return app(RichTextSanitizer::class)->sanitize($html);
}

test('the allowed subset passes through', function () {
    $html = '<p>Fine <strong>yacht</strong> with <em>style</em>.</p><h3>Highlights</h3><ul><li>One</li><li>Two</li></ul>';

    expect(sanitize($html))->toBe($html);
})->group('LS-5');

test('script and style elements are removed with their content', function () {
    expect(sanitize('<p>Safe</p><script>alert(1)</script><style>p{}</style>'))
        ->toBe('<p>Safe</p>');
})->group('LS-5');

test('unknown elements are unwrapped to their text', function () {
    expect(sanitize('<div><p>Kept</p><span>text survives</span></div>'))
        ->toBe('<p>Kept</p>text survives');
})->group('LS-5');

test('event handlers and styles are stripped', function () {
    expect(sanitize('<p onclick="alert(1)" style="color:red" class="x">Text</p>'))
        ->toBe('<p>Text</p>');
})->group('LS-5');

test('only https links keep their href', function () {
    expect(sanitize('<a href="https://example.com/x">ok</a>'))
        ->toBe('<a href="https://example.com/x" rel="noopener noreferrer" target="_blank">ok</a>')
        ->and(sanitize('<a href="javascript:alert(1)">bad</a>'))->toBe('<a>bad</a>')
        ->and(sanitize('<a href="http://example.com">plain</a>'))->toBe('<a>plain</a>');
})->group('LS-5');

test('links to the node\'s own websites are stripped', function () {
    $html = '<p>See <a href="https://www.this-node.example/yacht/1">our site</a> or <a href="https://partner.example/x">elsewhere</a>.</p>';

    expect(app(RichTextSanitizer::class)->sanitize($html, ['this-node.example']))
        ->toBe('<p>See <a>our site</a> or <a href="https://partner.example/x" rel="noopener noreferrer" target="_blank">elsewhere</a>.</p>');
});

test('images and iframes never survive', function () {
    expect(sanitize('<p>Text</p><img src="https://x.example/a.jpg"><iframe src="https://x.example"></iframe>'))
        ->toBe('<p>Text</p>');
})->group('LS-5');

test('html comments are dropped so comment-boundary mXSS cannot smuggle live markup', function () {
    // libxml (this parser) and the browser's HTML5 tokenizer disagree on
    // where a comment ends; passing comments through verbatim let an
    // abrupt-close payload reintroduce an executing element. Comments are
    // now removed outright, so nothing survives to be re-parsed.
    expect(sanitize('<p>Beautiful.</p><!--><img src=x onerror=alert(1)>-->'))
        ->not->toContain('<img')
        ->not->toContain('onerror')
        ->not->toContain('<!--');

    expect(sanitize('<!---><svg onload=alert(1)>-->'))->toBe('');

    expect(sanitize('<p>a<!--[if]><img src=x onerror=alert(1)>-->b</p>'))
        ->toBe('<p>ab</p>');
})->group('LS-5');
