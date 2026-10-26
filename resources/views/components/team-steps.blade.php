@props(['active'=>null])
<div class="team-steps" aria-label="{{ __('توزيع مهام الفريق') }}">
    @foreach(\App\Services\OrderWorkflow::stages() as $key=>$stage)
        <article @class(['team-step','current'=>$active===$key]) data-team-step="{{ $key }}">
            <b class="team-step-number" aria-hidden="true">{{ str_pad((string)$loop->iteration,2,'0',STR_PAD_LEFT) }}</b>
            <div><strong>{{ $stage['owner'] }}</strong><h2>{{ __($stage['title']) }}</h2><p>{{ __($stage['description']) }}</p></div>
        </article>
    @endforeach
</div>
