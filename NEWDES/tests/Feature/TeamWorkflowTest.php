<?php

namespace Tests\Feature;

use App\Jobs\ExportOrderItem;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Design;
use App\Models\DesignVersion;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductionFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class TeamWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function item(Order $order, string $exportStatus = 'ready'): OrderItem
    {
        $document = ['version' => 1, 'sides' => ['front' => ['background' => '#18181E', 'elements' => []]]];
        $options = ['mode' => 'single', 'printed_side' => 'front'];
        $templates = ['front' => ['name' => 'Approved fixture', 'version' => 1, 'approved_at' => '2026-10-26', 'geometry' => []]];
        $design = Design::create(['id' => (string) Str::uuid(), 'guest_session_id' => $order->guest_session_id, 'name' => 'Frozen artwork', 'document' => $document, 'options' => $options]);
        $version = DesignVersion::create(['design_id' => $design->id, 'revision' => 1, 'document' => $document, 'options' => $options, 'templates' => $templates]);

        return OrderItem::create(['order_id' => $order->id, 'design_version_id' => $version->id, 'snapshot' => ['name' => 'Frozen artwork', 'design_revision' => 1, 'document' => $document, 'options' => $options, 'templates' => $templates, 'assets' => []], 'quantity' => 1, 'unit_price_iqd' => 12000, 'export_status' => $exportStatus]);
    }

    public function test_admin_board_routes_real_orders_to_the_three_named_stages_without_creating_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $incoming = Order::factory()->create(['status' => 'pending_confirmation']);
        $production = Order::factory()->create(['status' => 'approved']);
        $shipping = Order::factory()->create(['status' => 'ready_to_ship']);
        $done = Order::factory()->create(['status' => 'delivered']);

        $this->actingAs($admin)->get('/admin/tasks')->assertOk()->assertSee('جواد')->assertSee('مهند')->assertSee('جعفر')->assertSee($incoming->number)->assertSee($production->number)->assertSee($shipping->number)->assertDontSee($done->number)->assertViewHas('lanes', fn (array $lanes): bool => $lanes['orders']['count'] === 1 && $lanes['production']['count'] === 1 && $lanes['shipping']['count'] === 1);

        $this->assertDatabaseCount('users', 1);
        $this->get('/admin/orders?stage=production')->assertOk()->assertSee($production->number)->assertDontSee($incoming->number)->assertDontSee($shipping->number);
        $this->getJson('/admin/orders?stage=invalid')->assertUnprocessable();
    }

    #[TestWith(['orders', 'pending_confirmation'])]
    #[TestWith(['production', 'approved'])]
    #[TestWith(['shipping', 'ready_to_ship'])]
    public function test_staff_land_on_their_own_task_queue(string $role, string $status): void
    {
        $user = User::factory()->create(['role' => $role]);
        $own = Order::factory()->create(['status' => $status]);
        $other = Order::factory()->create(['status' => 'delivered']);

        $this->actingAs($user)->get('/admin')->assertRedirect(route('admin.tasks.index'));
        $this->get('/admin/tasks')->assertOk()->assertSee('data-task-lane="'.$role.'"', false)->assertSee($own->number)->assertDontSee($other->number)->assertViewHas('lanes', fn (array $lanes): bool => count($lanes) === 1 && isset($lanes[$role]));
        $this->get('/admin/accounting')->assertForbidden();
        $this->get('/admin/settings')->assertForbidden();
    }

    public function test_order_moves_from_jawad_to_muhannad_then_jaafar_with_recorded_handoffs(): void
    {
        Queue::fake();
        $jawad = User::factory()->create(['name' => 'جواد', 'role' => 'orders']);
        $muhannad = User::factory()->create(['name' => 'مهند', 'role' => 'production']);
        $jaafar = User::factory()->create(['name' => 'جعفر', 'role' => 'shipping']);
        $order = Order::factory()->create(['status' => 'new']);
        $item = $this->item($order);
        $snapshot = $item->snapshot;
        $statusUrl = '/admin/orders/'.$order->id.'/status';

        $this->actingAs($jawad)->post($statusUrl, ['status' => 'pending_confirmation'])->assertRedirect();
        $this->post($statusUrl, ['status' => 'approved'])->assertRedirect();
        $this->assertSame('approved', $order->fresh()->status);
        Queue::assertPushed(ExportOrderItem::class, 1);
        $this->actingAs($muhannad)->postJson($statusUrl, ['status' => 'printing'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $item->refresh()->update(['export_status' => 'ready']);
        $this->post($statusUrl, ['status' => 'printing'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($statusUrl, ['status' => 'ready_to_ship'])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($jaafar)->get('/admin/orders/'.$order->id)->assertOk()->assertDontSee('<option value="shipped">', false);
        $this->actingAs($jaafar)->postJson($statusUrl, ['status' => 'shipped'])->assertUnprocessable()->assertJsonPath('errors.status.0', 'سجّل استلام الطلب الجاهز لدى مسؤول التوصيل أولاً.');
        $this->post('/admin/orders/'.$order->id.'/shipping-receipt', ['shipping_received_by' => $jawad->id])->assertRedirect(route('admin.orders.show', $order));
        $this->assertSame($jaafar->id, $order->fresh()->shipping_received_by);
        $this->get('/admin/orders/'.$order->id)->assertOk()->assertSee('<option value="shipped">', false);
        $this->post($statusUrl, ['status' => 'shipped'])->assertRedirect();
        $this->post($statusUrl, ['status' => 'delivered'])->assertRedirect();

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame($snapshot, $item->fresh()->snapshot);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.shipping_received', 'subject_id' => $order->id, 'user_id' => $jaafar->id]);
        $this->assertSame(6, AuditLog::where('action', 'order.status')->count());
        $this->assertDatabaseCount('users', 3);
    }

    #[TestWith(['orders', 'approved', 'printing'])]
    #[TestWith(['orders', 'ready_to_ship', 'shipped'])]
    #[TestWith(['orders', 'shipped', 'delivered'])]
    #[TestWith(['production', 'pending_confirmation', 'approved'])]
    #[TestWith(['production', 'pending_confirmation', 'design_review'])]
    #[TestWith(['production', 'ready_to_ship', 'shipped'])]
    #[TestWith(['shipping', 'pending_confirmation', 'approved'])]
    #[TestWith(['shipping', 'approved', 'printing'])]
    public function test_staff_cannot_perform_another_stage_action(string $role, string $current, string $next): void
    {
        $user = User::factory()->create(['role' => $role]);
        $order = Order::factory()->create(['status' => $current, 'shipping_received_at' => now()]);

        $this->actingAs($user)->postJson('/admin/orders/'.$order->id.'/status', ['status' => $next])->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->assertSame($current, $order->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[TestWith(['admin'])]
    #[TestWith(['shipping'])]
    public function test_shipping_receipt_is_idempotent_and_preserves_the_actual_first_receiver(string $role): void
    {
        $this->travelTo(now()->setDate(2026, 10, 26)->setTime(12, 0));
        $first = User::factory()->create(['role' => $role]);
        $second = User::factory()->create(['role' => 'shipping']);
        $order = Order::factory()->create(['status' => 'ready_to_ship']);
        $url = '/admin/orders/'.$order->id.'/shipping-receipt';
        $this->actingAs($first)->post($url)->assertRedirect();
        $time = $order->fresh()->shipping_received_at->toISOString();
        $this->travel(5)->minutes();
        $this->actingAs($second)->post($url)->assertRedirect();

        $this->assertSame($first->id, $order->fresh()->shipping_received_by);
        $this->assertSame($time, $order->fresh()->shipping_received_at->toISOString());
        $this->assertSame('ready_to_ship', $order->fresh()->status);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    #[TestWith(['orders'])]
    #[TestWith(['production'])]
    #[TestWith(['customer'])]
    public function test_other_roles_cannot_receive_a_shipping_order(string $role): void
    {
        $order = Order::factory()->create(['status' => 'ready_to_ship']);
        $this->actingAs(User::factory()->create(['role' => $role]))->postJson('/admin/orders/'.$order->id.'/shipping-receipt')->assertForbidden();
        $this->assertNull($order->fresh()->shipping_received_at);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_shipping_cannot_receive_an_unfinished_order_or_view_its_customer_data(): void
    {
        $order = Order::factory()->create(['status' => 'printing']);
        $ready = Order::factory()->create(['status' => 'ready_to_ship']);
        $this->actingAs(User::factory()->create(['role' => 'shipping']));

        $this->postJson('/admin/orders/'.$order->id.'/shipping-receipt')->assertUnprocessable();
        $this->get('/admin/orders/'.$order->id)->assertNotFound();
        $this->get('/admin/orders')->assertOk()->assertSee($ready->number)->assertDontSee($order->number);
        $this->assertNull($order->fresh()->shipping_received_at);
    }

    public function test_returning_a_ready_order_to_design_review_invalidates_the_shipping_receipt(): void
    {
        $user = User::factory()->create(['role' => 'production']);
        $order = Order::factory()->create(['status' => 'ready_to_ship', 'shipping_received_at' => now(), 'shipping_received_by' => $user->id]);
        $this->actingAs($user)->post('/admin/orders/'.$order->id.'/status', ['status' => 'design_review'])->assertRedirect();
        $this->assertNull($order->fresh()->shipping_received_at);
        $this->assertNull($order->fresh()->shipping_received_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.status', 'subject_id' => $order->id]);
    }

    #[TestWith(['admin'])]
    #[TestWith(['production'])]
    public function test_production_file_downloads_are_logged_for_authorized_roles(string $role): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['role' => $role]);
        $order = Order::factory()->create();
        $item = $this->item($order);
        Storage::disk('local')->put('private/production.zip', 'zip bytes');
        $file = ProductionFile::create(['order_item_id' => $item->id, 'side' => 'all', 'kind' => 'zip', 'path' => 'private/production.zip', 'sha256' => hash('sha256', 'zip bytes')]);

        $this->actingAs($user)->get('/admin/files/'.$file->id)->assertDownload('production.zip')->assertStreamedContent('zip bytes');

        $this->assertDatabaseHas('audit_logs', ['action' => 'production.download_requested', 'subject_id' => $order->id, 'user_id' => $user->id]);
        $this->get('/admin/orders/'.$order->id)->assertOk()->assertSee('طلب تنزيل ملف إنتاج');
    }

    #[TestWith(['orders'])]
    #[TestWith(['shipping'])]
    public function test_other_staff_cannot_download_or_regenerate_production_files(string $role): void
    {
        $order = Order::factory()->create(['status' => 'ready_to_ship']);
        $item = $this->item($order);
        $file = ProductionFile::create(['order_item_id' => $item->id, 'side' => 'all', 'kind' => 'zip', 'path' => 'private/never-read.zip', 'sha256' => str_repeat('a', 64)]);
        $this->actingAs(User::factory()->create(['role' => $role]));

        $this->get('/admin/files/'.$file->id)->assertForbidden();
        $this->postJson('/admin/items/'.$item->id.'/retry')->assertForbidden();
        $this->get('/admin/orders/'.$order->id)->assertOk()->assertDontSee('/admin/files/'.$file->id, false)->assertDontSee('private/never-read.zip');
        $this->assertSame('ready', $item->fresh()->export_status);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_shipping_sees_delivery_details_without_original_artwork_or_payment_controls(): void
    {
        $customer = Customer::factory()->create(['name' => 'Private customer']);
        $order = Order::factory()->for($customer)->create(['status' => 'ready_to_ship']);
        $item = $this->item($order);
        $asset = Asset::create(['id' => (string) Str::uuid(), 'guest_session_id' => $order->guest_session_id, 'path' => 'private/customer.png', 'mime' => 'image/png', 'width' => 500, 'height' => 500, 'bytes' => 100, 'sha256' => str_repeat('b', 64)]);
        $this->actingAs(User::factory()->create(['role' => 'shipping']));

        $this->get('/admin/orders/'.$order->id)->assertOk()->assertSee('Private customer')->assertSee('+9647701234567')->assertDontSee('data-design-preview', false)->assertDontSee('/payment', false)->assertDontSee('مواصفات الإنتاج الثابتة');
        $this->get('/admin/items/'.$item->id.'/assets/'.$asset->id)->assertForbidden();
        $this->get('/admin/items/'.$item->id.'/art/key')->assertForbidden();
        $this->get('/admin/assets/'.$asset->id)->assertForbidden();
        $this->postJson('/admin/orders/'.$order->id.'/payment', ['amount_iqd' => 100, 'reference' => 'not allowed'])->assertForbidden();
        $this->assertDatabaseCount('collections', 0);
    }

    public function test_admin_can_assign_the_shipping_role_later_without_creating_new_accounts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $future = User::factory()->create();
        $this->actingAs($admin)->get('/admin/users')->assertOk()->assertSee('استلام الطلبات — جواد')->assertSee('تجهيز الإنتاج — مهند')->assertSee('التوصيل — جعفر');
        $this->post('/admin/users/'.$future->id, ['role' => 'shipping'])->assertRedirect('/admin/users');
        $this->assertSame('shipping', $future->fresh()->role);
        $this->assertDatabaseCount('users', 2);
        $this->actingAs($future)->postJson('/admin/users/'.$admin->id, ['role' => 'customer'])->assertForbidden();
    }

    public function test_shipping_login_enters_staff_workflow_and_guests_cannot_access_it(): void
    {
        $this->get('/admin/tasks')->assertRedirect('/login');
        $user = User::factory()->create(['role' => 'shipping', 'password' => 'valid-test-password']);
        $this->post('/login', ['email' => $user->email, 'password' => 'valid-test-password'])->assertRedirect('/admin');
        $this->get('/admin')->assertRedirect(route('admin.tasks.index'));
        $this->actingAs(User::factory()->create())->get('/admin/tasks')->assertForbidden();
    }

    public function test_all_tasks_remain_accessible_when_the_board_has_more_than_twelve_orders(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Order::factory()->count(13)->create();

        $this->actingAs($admin)->get('/admin/tasks')->assertOk()->assertViewHas('lanes', fn (array $lanes): bool => $lanes['orders']['count'] === 13 && $lanes['orders']['orders']->count() === 12)->assertSee(route('admin.orders.index', ['stage' => 'orders']), false);
        $this->get('/admin/orders?stage=orders')->assertOk()->assertViewHas('orders', fn ($orders): bool => $orders->total() === 13 && $orders->count() === 13);
    }

    public function test_a_missing_production_file_returns_404_without_a_download_record(): void
    {
        Storage::fake('local');
        $order = Order::factory()->create();
        $item = $this->item($order);
        $file = ProductionFile::create(['order_item_id' => $item->id, 'side' => 'all', 'kind' => 'zip', 'path' => 'missing.zip', 'sha256' => str_repeat('c', 64)]);

        $this->actingAs(User::factory()->create(['role' => 'production']))->get('/admin/files/'.$file->id)->assertNotFound();

        $this->assertDatabaseCount('audit_logs', 0);
    }
}
