@extends('layouts.app')

@section('title', $ruleId ? 'Edit rule' : 'New rule')

@section('content')
    <livewire:vatssa-discord-rule-editor :rule-id="$ruleId" />
@endsection
