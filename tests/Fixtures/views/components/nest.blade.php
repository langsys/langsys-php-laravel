@if ($n > 0)<x-nest :n="$n - 1" :value="$value" />@else<b>{{ $value }}</b>@endif
