<?php

namespace App\Services;

use App\Models\Order;
use App\Models\User;

class OrderWorkflow
{
    /** @return array<string, array{owner: string, title: string, description: string, statuses: list<string>}> */
    public static function stages(): array
    {
        return [
            'orders' => ['owner' => 'جواد', 'title' => 'استلام وتأكيد الطلبات', 'description' => 'استلام الطلب، مراجعة التفاصيل والتواصل مع الزبون، ثم اعتماده للإنتاج.', 'statuses' => ['new', 'pending_confirmation', 'design_review']],
            'production' => ['owner' => 'مهند', 'title' => 'التصميم وتجهيز الإنتاج', 'description' => 'تنزيل ملفات التصميم، مراجعة مواصفات الإنتاج، ثم تجهيز الطلب وتسليمه جاهزاً.', 'statuses' => ['approved', 'printing']],
            'shipping' => ['owner' => 'جعفر', 'title' => 'استلام الجاهز والتوصيل', 'description' => 'استلام الطلب الجاهز، تدقيق العنوان، وتسليمه للتوصيل ومتابعة التسليم.', 'statuses' => ['ready_to_ship', 'shipped']],
        ];
    }

    public static function stage(Order $order): ?string
    {
        foreach (self::stages() as $key => $stage) {
            if (in_array($order->status, $stage['statuses'], true)) {
                return $key;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function nextStatuses(Order $order, User $user): array
    {
        $transitions = match ($order->status) {
            'new' => ['pending_confirmation', 'cancelled'],
            'pending_confirmation' => ['design_review', 'approved', 'cancelled'],
            'design_review' => ['pending_confirmation', 'approved', 'cancelled'],
            'approved' => ['printing', 'design_review', 'cancelled'],
            'printing' => ['ready_to_ship', 'design_review'],
            'ready_to_ship' => ['shipped', 'design_review'],
            'shipped' => ['delivered', 'returned'],
            'delivered' => ['returned'],
            default => [],
        };
        $allowed = match ($user->role) {
            'admin' => $transitions,
            'orders' => in_array($order->status, self::stages()['orders']['statuses'], true) ? ['pending_confirmation', 'design_review', 'approved', 'cancelled'] : [],
            'production' => in_array($order->status, ['approved', 'printing', 'ready_to_ship'], true) ? ['printing', 'ready_to_ship', 'design_review'] : [],
            'shipping' => ['shipped', 'delivered', 'returned'],
            default => [],
        };

        return array_values(array_intersect($transitions, $allowed));
    }

    public static function canDownload(User $user): bool
    {
        return in_array($user->role, ['admin', 'production'], true);
    }

    public static function canView(Order $order, User $user): bool
    {
        return in_array($user->role, User::STAFF_ROLES, true) && ($user->role !== 'shipping' || in_array($order->status, ['ready_to_ship', 'shipped', 'delivered', 'returned'], true));
    }
}
