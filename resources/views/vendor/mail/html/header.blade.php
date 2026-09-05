@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<span class="mark" aria-hidden="true">H</span>{{ $slot }}
</a>
</td>
</tr>
