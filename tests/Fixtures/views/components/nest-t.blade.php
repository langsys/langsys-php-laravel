@if ($n > 0)<x-nest-t :n="$n - 1" />@else{{ __('Pay now') }}@endif
