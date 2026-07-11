{{-- $rows: array of [label, value] pairs. Shared by every transactional email
     so order/payment/withdrawal facts always read the same way. --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;">
    @foreach($rows as $index => [$label, $value])
        <tr>
            <td style="padding:10px 16px;font-size:13px;color:#64748b;background-color:{{ $index % 2 === 0 ? '#f8fafc' : '#ffffff' }};width:44%;border-bottom:{{ $loop->last ? 'none' : '1px solid #e2e8f0' }};">{{ $label }}</td>
            <td style="padding:10px 16px;font-size:13px;font-weight:700;color:#0f172a;background-color:{{ $index % 2 === 0 ? '#f8fafc' : '#ffffff' }};border-bottom:{{ $loop->last ? 'none' : '1px solid #e2e8f0' }};">{{ $value }}</td>
        </tr>
    @endforeach
</table>
