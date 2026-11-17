@php
    $details = [];
    $messages = [];
    foreach ($introLines as $line) {
        if (is_string($line) && preg_match('/^(Category|Store|Due date|Renewal date|Amount(?: due)?|Voucher reference|Payment reference|Payment date|Recorded by|Processed by|Date vouchered|Record):\s*(.*)$/u', $line, $matches)) {
            $details[] = ['label' => $matches[1], 'value' => str_replace('|', '\\|', $matches[2])];
        } else {
            $messages[] = $line;
        }
    }
@endphp
<x-mail::message>
# {{ $greeting ?: ($level === 'error' ? __('Whoops!') : __('Hello!')) }}

@foreach ($messages as $line)
{{ $line }}

@endforeach
@if (count($details))
<x-mail::table>
| REQUEST DETAILS | |
| :--- | ---: |
@foreach ($details as $detail)
| {{ $detail['label'] }} | @if (str_starts_with($detail['label'], 'Amount'))**{{ $detail['value'] }}**@else{{ $detail['value'] }}@endif |
@endforeach
</x-mail::table>
@endif

@isset($actionText)
<x-mail::button :url="$actionUrl" :color="in_array($level, ['success', 'error']) ? $level : 'primary'">
{{ $actionText }}
</x-mail::button>
@endisset

@if (count($outroLines))
<x-mail::panel>
@foreach ($outroLines as $line)
{{ $line }}

@endforeach
</x-mail::panel>
@endif

{{ $salutation ?: 'BW Superbakeshop Operations' }}

@isset($actionText)
<x-slot:subcopy>
If the button does not open, copy this link into your browser:<br>
[{{ $displayableActionUrl }}]({{ $actionUrl }})
</x-slot:subcopy>
@endisset
</x-mail::message>
