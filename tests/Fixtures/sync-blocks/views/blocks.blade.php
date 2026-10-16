<p>@lang Pay now @endlang</p>
<p>@t Thanks for shopping @endt</p>
<p>@lang(['name' => $name])
    Hello :name, welcome
@endlang</p>
<p>@lang('Place order')</p>
<p>@lang Hello {{ $name }} @endlang</p>
<p>@lang Write to help@example.com @endlang</p>
<h1>@lang ("Spaced call")</h1>
<p>Mail support@t-online.de for help.</p>
{{-- @t is @lang by another name --}}
<p>@lang Hello @if(true) friend @endif now @endlang</p>
<p>@lang(['name' => $name]) Pick @lang(['item' => $item]) :item @endlang for :name @endlang</p>
<p>@lang Last block @endlang</p>
