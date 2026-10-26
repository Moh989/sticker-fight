<x-admin-layout :title="__('المستخدمون والصلاحيات')">
    <div class="admin-heading"><div><h1>{{ __('المستخدمون والصلاحيات') }}</h1><p>{{ __('أضف حسابات الفريق وحدد صلاحية كل مستخدم.') }}</p></div><a href="#create-user" class="button primary small"><x-icon name="plus" size="17"/>{{ __('إضافة مستخدم') }}</a></div>
    <section id="create-user" class="panel admin-user-create" tabindex="-1">
        <h2>{{ __('إضافة مستخدم') }}</h2>
        <form method="post" action="{{ route('admin.users.store') }}" class="resource-form" autocomplete="off" data-create-user-form>
            @csrf
            <div class="form-grid">
                <label>{{ __('الاسم') }}<input name="name" value="{{ old('name') }}" required minlength="2" maxlength="100" autocomplete="off"></label>
                <label>{{ __('البريد الإلكتروني') }}<input name="email" type="email" value="{{ old('email') }}" required maxlength="200" autocomplete="off" dir="ltr"></label>
                <label>{{ __('كلمة المرور') }}<input name="password" type="password" required minlength="12" maxlength="72" autocomplete="new-password" dir="ltr" aria-describedby="new-user-password-hint"><small id="new-user-password-hint" class="muted">{{ __('12 حرفاً على الأقل.') }}</small></label>
                <label>{{ __('تأكيد كلمة المرور') }}<input name="password_confirmation" type="password" required minlength="12" maxlength="72" autocomplete="new-password" dir="ltr"></label>
                <label class="span-2">{{ __('الدور') }}<select name="role" required><option value="" disabled @selected(!old('role'))>{{ __('اختر دور المستخدم') }}</option>@foreach(\App\Models\User::ROLES as $key=>$label)<option value="{{ $key }}" @selected(old('role')===$key)>{{ __($label) }}</option>@endforeach</select></label>
            </div>
            <div><button class="button primary" type="submit">{{ __('إنشاء المستخدم') }} <x-icon name="check" size="17"/></button></div>
        </form>
    </section>
    <x-team-steps/>
    <div class="section-heading"><h2 class="team-panel-title">{{ __('المستخدمون الحاليون') }}</h2><span class="badge">{{ $users->total() }}</span></div>
    <p class="tool-hint">{{ __('اختر لكل حساب دور مرحلته. أسماء الفريق توضّح توزيع المهام ولا تغيّر اسم صاحب الحساب.') }}</p>
    <div class="table-scroll"><table><thead><tr><th>{{ __('الاسم') }}</th><th>{{ __('البريد') }}</th><th>{{ __('الدور') }}</th><th>{{ __('حفظ') }}</th></tr></thead><tbody>
        @foreach($users as $user)<tr><td>{{ $user->name }}</td><td dir="ltr">{{ $user->email }}</td><td><form id="role-{{ $user->id }}" action="{{ route('admin.users.update',$user) }}" method="post">@csrf<select name="role" aria-label="{{ __('دور :name',['name'=>$user->name]) }}">@foreach(\App\Models\User::ROLES as $key=>$label)<option value="{{ $key }}" @selected($user->role===$key)>{{ __($label) }}</option>@endforeach</select></form></td><td><button form="role-{{ $user->id }}" class="button secondary small">{{ __('حفظ') }}</button></td></tr>@endforeach
    </tbody></table></div>
    {{ $users->links() }}
</x-admin-layout>
