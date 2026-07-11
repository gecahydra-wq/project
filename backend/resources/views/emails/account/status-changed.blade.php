@extends('emails.layout')

@section('content')
    <p style="margin:0 0 12px;">Hi {{ $recipientName }},</p>
    <p style="margin:0 0 12px;">{{ $introLine }}</p>

    @if(!empty($restrictions))
        <div style="margin:0 0 16px;padding:14px 16px;border-radius:10px;background-color:#fef2f2;border:1px solid #fecaca;">
            <p style="margin:0 0 8px;font-size:13px;font-weight:700;color:#991b1b;">While suspended, you cannot:</p>
            <ul style="margin:0;padding-left:18px;font-size:13px;color:#7f1d1d;">
                @foreach($restrictions as $restriction)
                    <li style="margin:0 0 4px;">{{ $restriction }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @include('emails.partials.details', ['rows' => $rows])

    <div style="margin:16px 0 0;padding:14px 16px;border-radius:10px;background-color:#e0f2fe;border:1px solid #bae6fd;">
        <p style="margin:0;font-size:13px;color:#075985;">{{ $appealLine }}</p>
    </div>
@endsection
