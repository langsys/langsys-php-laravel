{{-- The order page.
     A comment Blade drops when it compiles,
     so compiled lines no longer match these. --}}
<h1>@lang('Place order')</h1>
<p>{{ __('Total') }}</p>
<p>{{ __($label) }}</p>
<p>@t('Read our <a href="/terms">terms</a> today')</p>
<p>@lang('legal.terms')</p>
<p>@lang('legal.privacy')</p>
<p>{{ strlen($label) + 1 }} items</p>
<p>@choice(':count <b>item</b> left|:count <b>items</b> left', $count)</p>
