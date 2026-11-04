{{--
    Search engine and link preview tags for a public page:
    @include('game-platform::meta', ['meta' => ['title' => ..., 'description' => ..., 'url' => ..., 'image' => ...], 'genre' => ['Simulation']])
--}}
<meta name="description" content="{{ $meta['description'] }}">
<link rel="canonical" href="{{ $meta['url'] }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ config('app.name') }}">
<meta property="og:title" content="{{ $meta['title'] }}">
<meta property="og:description" content="{{ $meta['description'] }}">
<meta property="og:url" content="{{ $meta['url'] }}">
<meta property="og:image" content="{{ $meta['image'] }}">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="{{ $meta['title'] }}">
<meta name="twitter:description" content="{{ $meta['description'] }}">
<meta name="twitter:image" content="{{ $meta['image'] }}">
<script type="application/ld+json">{!! json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'VideoGame',
    'name' => config('app.name'),
    'description' => $meta['description'],
    'url' => $meta['url'],
    'image' => $meta['image'],
    'genre' => $genre ?? [],
    'gamePlatform' => 'Web browser',
    'applicationCategory' => 'Game',
    'operatingSystem' => 'Any',
    'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => $currency ?? 'GBP'],
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!}</script>
