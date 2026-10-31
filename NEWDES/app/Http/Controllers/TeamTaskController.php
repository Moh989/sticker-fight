<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Order;
use App\Services\OrderWorkflow;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeamTaskController extends Controller
{
    public function index(): View
    {
        $stages = OrderWorkflow::stages();
        $role = auth()->user()->role;
        $visible = $role === 'admin' ? $stages : array_intersect_key($stages, [$role => true]);
        $lanes = [];
        foreach ($visible as $key => $stage) {
            $query = Order::whereIn('status', $stage['statuses']);
            $lanes[$key] = $stage + ['count' => (clone $query)->count(), 'orders' => $query->withCount('items')->with(['items:id,order_id,export_status'])->oldest()->limit(12)->get()];
        }

        return view('admin.tasks', compact('stages', 'lanes'));
    }

    public function receiveShipping(Order $order): RedirectResponse
    {
        abort_unless(in_array(auth()->user()->role, ['admin', 'shipping'], true), 403);
        DB::transaction(function () use ($order): void {
            $record = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($record->status !== 'ready_to_ship') {
                throw ValidationException::withMessages(['status' => __('استلام التوصيل متاح للطلبات الجاهزة فقط.')]);
            }
            if ($record->shipping_received_at) {
                return;
            }
            $record->update(['shipping_received_at' => now(), 'shipping_received_by' => auth()->id()]);
            AuditLog::create(['user_id' => auth()->id(), 'action' => 'order.shipping_received', 'subject_type' => 'Order', 'subject_id' => $record->id, 'changes' => ['stage' => 'shipping']]);
        });

        return redirect()->route('admin.orders.show', $order)->with('success', __('تم تسجيل استلام الطلب الجاهز لدى مسؤول التوصيل.'));
    }
}
