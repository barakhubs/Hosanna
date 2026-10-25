<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use App\Settings\SettingsRepository;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): string|null
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $permissions = collect();
        if ($request->user()) {
            $user = $request->user();
            $role = Role::where('name',$user->user_role)->first();
            $permissions = $role->permissions;
        }

        $settings = app(SettingsRepository::class);
        $currencySettings = $settings->json('currency_settings') ?? [];
        $modules = $settings->modules();
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            'settings'=>[
                'shop_name'=>$settings->get('shop_name'),
                'currency_settings'=>$currencySettings,
            ],
            'modules'=>$modules,
            'userPermissions'=>$permissions->pluck('name'),
        ];
    }
}
