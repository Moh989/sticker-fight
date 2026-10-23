@props(['name'=>'arrow-left','size'=>20])
@php $paths=[
'arrow-left'=>'M19 12H5m7-7-7 7 7 7','arrow-up-left'=>'M17 17 7 7m0 10V7h10','arrow-right'=>'M5 12h14m-7-7 7 7-7 7',
'bag'=>'M6 7h12l2 14H4L6 7Zm3 0V5a3 3 0 0 1 6 0v2',
'search'=>'m21 21-4.3-4.3M19 11a8 8 0 1 1-16 0 8 8 0 0 1 16 0Z',
'heart'=>'M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z',
'menu'=>'M4 6h16M4 12h16M4 18h16','close'=>'m6 6 12 12M6 18 18 6','check'=>'m5 12 4 4L19 6',
'moon'=>'M20.9 13A9 9 0 0 1 11 3.1 9 9 0 1 0 20.9 13Z',
'sun'=>'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8ZM12 2v2m0 16v2M2 12h2m16 0h2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4',
'upload'=>'M12 16V3m-5 5 5-5 5 5M3 16v5h18v-5','sparkles'=>'m12 3 3 6 6 3-6 3-3 6-3-6-6-3 6-3 3-6Z',
'card'=>'M3 5h18v14H3V5Zm0 5h18M6 15h3','shield'=>'m12 3 9 4v5c0 5-9 10-9 10S3 17 3 12V7l9-4Zm-4 9 3 3 5-6',
'chevron'=>'m9 5 7 7-7 7','chevron-left'=>'m15 5-7 7 7 7','plus'=>'M12 5v14M5 12h14','minus'=>'M5 12h14','copy'=>'M8 8h13v13H8V8ZM3 16V3h13',
'undo'=>'m9 4-5 5 5 5M4 9h10a6 6 0 0 1 0 12','redo'=>'m15 4 5 5-5 5M20 9H10a6 6 0 0 0 0 12',
'trash'=>'M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7M14 10v7','lock'=>'M5 10h14v11H5V10Zm3 0V6a4 4 0 0 1 8 0v4',
'eye'=>'M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Zm13 0a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z',
'layers'=>'m12 2 10 6-10 6L2 8l10-6ZM2 12l10 6 10-6M2 16l10 6 10-6',
'download'=>'M12 3v13m-5-5 5 5 5-5M3 17v4h18v-4','grid'=>'M3 3h7v7H3V3Zm11 0h7v7h-7V3ZM3 14h7v7H3v-7Zm11 0h7v7h-7v-7',
'alert'=>'m12 3 10 18H2L12 3Zm0 6v5m0 3v1','truck'=>'M1 5h14v12H1V5Zm14 5h4l4 4v3h-8M7 19a2 2 0 1 1-4 0 2 2 0 0 1 4 0Zm14 0a2 2 0 1 1-4 0 2 2 0 0 1 4 0Z',
'settings'=>'M12 8a4 4 0 1 1 0 8 4 4 0 0 1 0-8ZM12 2v3m0 14v3M2 12h3m14 0h3M5 5l2 2m10 10 2 2M5 19l2-2M17 7l2-2',
'instagram'=>'M7 3h10a4 4 0 0 1 4 4v10a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4V7a4 4 0 0 1 4-4Zm9 9a4 4 0 1 1-8 0 4 4 0 0 1 8 0Zm1-6h.01',
'user'=>'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0ZM4 21v-2a8 8 0 0 1 16 0v2','type'=>'M4 4h16M12 4v17M8 21h8',
]; @endphp
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" data-icon="{{ $name }}" {{ $attributes }}><path d="{{ $paths[$name]??$paths['sparkles'] }}"/></svg>
