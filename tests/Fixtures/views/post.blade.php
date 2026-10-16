@extends('layouts.page')
@section('title'){{ $post }}@endsection
@section('description'){{ $summary }}@endsection
@section('count'){{ $count }}@endsection
@section('body')<p>{{ $post }}</p>@endsection
