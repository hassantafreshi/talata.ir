@if (session('error'))<span hidden data-flash="{{ session('error') }}" data-kind="error"></span>@endif
@if (session('status'))<span hidden data-flash="{{ session('status') }}"></span>@endif
