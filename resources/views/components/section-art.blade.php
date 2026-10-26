@props(['variant'=>'controller'])
@php $gradientId='sf-holo-'.\Illuminate\Support\Str::uuid(); @endphp
<svg viewBox="0 0 400 280" fill="none" aria-hidden="true" focusable="false" {{ $attributes->class(['section-art']) }}>
    <ellipse cx="200" cy="242" rx="133" ry="15" fill="#000" opacity=".18"/>
    @switch($variant)
        @case('controller')
            <path d="M118 81h164c28 0 45 21 53 51l21 76c8 31-21 46-42 27l-46-40H132l-46 40c-21 19-50 4-42-27l21-76c8-30 25-51 53-51Z" fill="#18181E" stroke="#5A5364" stroke-width="3"/>
            <path d="M125 83h150l-12 62H137Z" fill="#FF2D8D"/>
            <path d="m140 83 47 62m-19-62 47 62m-18-62 47 62" stroke="#0A0A0D" stroke-width="11"/>
            <path d="M93 112h18v18h18v18h-18v18H93v-18H75v-18h18Z" fill="#F7F5F8"/>
            <g fill="#FF78B5"><circle cx="298" cy="117" r="9"/><circle cx="278" cy="138" r="9"/><circle cx="318" cy="138" r="9"/><circle cx="298" cy="159" r="9"/></g>
            <g fill="#0A0A0D" stroke="#77717F" stroke-width="3"><circle cx="152" cy="174" r="23"/><circle cx="248" cy="174" r="23"/></g>
            <path d="M180 162h40" stroke="#77717F" stroke-width="5" stroke-linecap="round"/>
            @break
        @case('console')
            <path d="m123 43 125 15 47 24v150l-132 15-40-25Z" fill="#18181E" stroke="#5A5364" stroke-width="3"/>
            <path d="m123 43 40 28v176l-40-25Z" fill="#FF2D8D"/>
            <path d="m163 71 132 11M163 172l132-11" stroke="#5A5364" stroke-width="3"/>
            <path d="m186 108 83-4m-83 16 83-4m-83 16 83-4" stroke="#FF2D8D" stroke-width="6"/>
            <circle cx="272" cy="210" r="5" fill="#F7F5F8"/>
            <path d="M72 162h70l19 60H53Z" fill="#0A0A0D" stroke="#77717F" stroke-width="3"/>
            <path d="M79 184h23m-12-11v22m23-8h17" stroke="#FF78B5" stroke-width="5"/>
            @break
        @case('laptop')
            <path d="m97 47 214 19-18 149-214-19Z" fill="#18181E" stroke="#77717F" stroke-width="3"/>
            <path d="m109 63 184 16-15 118-184-16Z" fill="#FF2D8D"/>
            <path d="m108 63 131 132m-86-128 130 130m-86-126 91 92" stroke="#0A0A0D" stroke-width="17"/>
            <path d="m79 196 214 19 43 24-227-12-58-13Z" fill="#34343E" stroke="#77717F" stroke-width="2"/>
            <path d="m167 206 54 5 8 9-53-4Z" fill="#B8B4BE"/>
            @break
        @case('packaging')
            <path d="m74 103 125-52 127 56v107l-128 42-124-48Z" fill="#18181E" stroke="#77717F" stroke-width="3"/>
            <path d="m74 103 124 52 128-48m-128 48v101" stroke="#77717F" stroke-width="3"/>
            <path d="m142 74 123 57-41 13-123-58Z" fill="#FF2D8D"/>
            <rect x="125" y="133" width="88" height="56" rx="9" transform="rotate(22 125 133)" fill="#FF2D8D"/>
            <path d="m133 157 45 18m-50-5 26 10" stroke="#0A0A0D" stroke-width="6"/>
            @break
        @case('hologram')
            <defs><linearGradient id="{{ $gradientId }}" x1="80" y1="50" x2="300" y2="240" gradientUnits="userSpaceOnUse"><stop stop-color="#B5E5D5"/><stop offset=".24" stop-color="#C5B3F1"/><stop offset=".48" stop-color="#FF78B5"/><stop offset=".72" stop-color="#F4E3A9"/><stop offset="1" stop-color="#94D6DB"/></linearGradient></defs>
            <path d="m129 42 160 33 35 47-26 112c-2 12-10 18-23 15L85 209c-12-2-18-10-15-23L97 60c3-14 16-21 32-18Z" fill="url(#{{ $gradientId }})" stroke="#F7F5F8" stroke-width="3"/>
            <path d="m289 75-9 42c-3 12 2 19 14 17l30-12" fill="#F7F5F8"/>
            <path d="m165 100 70 15-15 70-70-15Z" stroke="#0A0A0D" stroke-width="8"/>
            <path d="m175 120 25 5m-29 11 39 8m-43 9 31 7" stroke="#0A0A0D" stroke-width="6"/>
            @break
        @case('qr')
            <rect x="103" y="39" width="196" height="198" rx="23" transform="rotate(9 103 39)" fill="#FF2D8D"/>
            <g stroke="#0A0A0D" stroke-width="9"><path d="M126 83h42v42h-42ZM207 83h42v42h-42ZM126 164h42v42h-42Z"/></g>
            <g fill="#0A0A0D"><path d="M137 94h20v20h-20ZM218 94h20v20h-20ZM137 175h20v20h-20ZM188 145h21v21h-21ZM228 145h21v21h-21ZM208 185h21v21h-21ZM228 165h21v21h-21ZM188 185h20v21h-20Z"/></g>
            @break
        @default
            <rect x="83" y="49" width="234" height="172" rx="16" transform="rotate(-9 83 49)" fill="#34343E" stroke="#77717F" stroke-width="2"/>
            <path d="m109 78 179 27-21 131-179-27Z" fill="#FF2D8D" stroke="#F7F5F8" stroke-width="3"/>
            <path d="m132 119 120 18m-126 6 105 16m-111 7 75 11" stroke="#0A0A0D" stroke-width="12"/>
            <path d="m273 183-6 53 40-39Z" fill="#F7F5F8"/>
    @endswitch
</svg>
