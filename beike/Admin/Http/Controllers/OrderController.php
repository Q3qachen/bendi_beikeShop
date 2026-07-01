<?php
/**
 * OrderController.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2022-07-05 10:45:26
 * @modified   2022-07-05 10:45:26
 */

namespace Beike\Admin\Http\Controllers;

use Beike\Admin\Http\Resources\OrderSimple;
use Beike\Models\Order;
use Beike\Models\OrderShipment;
use Beike\Repositories\OrderRepo;
use Beike\Services\ShipmentService;
use Beike\Services\StateMachineService;
use Beike\Shop\Http\Resources\Account\OrderShippingList;
use Beike\Shop\Http\Resources\Account\OrderSimpleList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    /**
     * 获取订单列表
     *
     * @param Request $request
     * @return mixed
     * @throws \Exception
     */
    public function index(Request $request)
    {
        $orders = OrderRepo::filterOrders($request->all());
        $data   = [
            'orders'          => OrderSimpleList::collection($orders),
            'statuses'        => StateMachineService::getAllStatuses(),
            'type'            => 'index',
        ];
        $data = hook_filter('admin.order.index.data', $data);

        return view('admin::pages.orders.index', $data);
    }

    /**
     * 获取订单回收站列表
     *
     * @param Request $request
     * @return mixed
     * @throws \Exception
     */
    public function trashed(Request $request)
    {
        $requestData            = $request->all();
        $requestData['trashed'] = true;
        $orders                 = OrderRepo::filterOrders($requestData);
        $data                   = [
            'orders'          => OrderSimpleList::collection($orders),
            'statuses'        => StateMachineService::getAllStatuses(),
            'type'            => 'trashed',
        ];
        $data = hook_filter('admin.order.trashed.data', $data);

        return view('admin::pages.orders.index', $data);
    }

    /**
     * 导出订单列表
     *
     * @param Request $request
     * @return mixed
     * @throws \Exception
     */
    public function export(Request $request)
    {
        try {
            $orders = OrderRepo::filterAll($request->all());
            $items  = OrderSimple::collection($orders)->jsonSerialize();
            $items  = hook_filter('admin.order.export.data', $items);

            return $this->downloadCsv('orders', $items, 'order');
        } catch (\Exception $e) {
            return redirect(admin_route('orders.index'))->withErrors(['error' => $e->getMessage()]);
        }
    }

    /**
     * 查看单个订单
     *
     * @param Request $request
     * @param Order   $order
     * @return mixed
     * @throws \Exception
     */
    public function show(Request $request, Order $order)
    {
        $order->load(['orderTotals', 'orderHistories', 'orderShipments', 'orderPayments']);
        $data                     = hook_filter('admin.order.show.data', ['order' => $order, 'html_items' => []]);
        $data['statuses']         = StateMachineService::getInstance($order)->nextBackendStatuses();
        $data['expressCompanies'] = system_setting('base.express_company', []);
        hook_action('admin.order.show.after', $data);

        return view('admin::pages.orders.form', $data);
    }

    /**
     * 更新订单状态,添加订单更新日志
     *
     * @param Request $request
     * @param Order   $order
     * @return array
     * @throws \Throwable
     */
    public function updateStatus(Request $request, Order $order)
    {
        $status  = $request->get('status');
        $comment = $request->get('comment');

        $shipment = ShipmentService::handleShipment(\request('express_code'), \request('express_number'));

        $stateMachine = new StateMachineService($order);
        $stateMachine->setShipment($shipment)->changeStatus($status, $comment);

        $orderStatusData = $request->all();

        hook_action('admin.order.update_status.after', $orderStatusData);

        return json_success(trans('common.updated_success'));
    }

    /**
     * 更新订单单字段（订单号 / 创建时间 / 更新时间）
     * 供订单列表页双击单元格编辑使用
     *
     * @param Request $request
     * @param Order   $order
     * @return JsonResponse
     * @throws \Throwable
     */
    public function updateField(Request $request, Order $order): JsonResponse
    {
        $field = $request->get('field');
        $value = (string) $request->get('value', '');

        $allowedFields = ['number', 'created_at', 'updated_at'];
        if (! in_array($field, $allowedFields, true)) {
            return json_fail(trans('admin/order.edit_field_invalid_field'));
        }

        $parsed = null;
        if ($field === 'number') {
            $value = trim($value);
            if ($value === '') {
                return json_fail(trans('admin/order.edit_field_number_required'));
            }
            $exists = Order::query()
                ->where('number', $value)
                ->where('id', '<>', $order->id)
                ->exists();
            if ($exists) {
                return json_fail(trans('admin/order.edit_field_number_exists'));
            }
            $order->number = $value;
        } else {
            // created_at / updated_at
            try {
                $value = trim($value);
                // 兼容 datetime-local 的 "Y-m-dTH:i" 格式
                $value = str_replace('T', ' ', $value);
                $parsed = \Carbon\Carbon::parse($value)->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                return json_fail(trans('admin/order.edit_field_invalid_datetime'));
            }
            $order->{$field} = $parsed;
        }

        // 关闭 timestamps 自动更新，避免覆盖 updated_at
        $order->timestamps = false;
        $order->saveOrFail();

        hook_action('admin.order.update_field.after', ['order' => $order, 'field' => $field, 'value' => $value]);

        $displayValue = $field === 'number' ? $value : $parsed;

        return json_success(trans('common.updated_success'), ['value' => $displayValue]);
    }

    /**
     * 更新发货信息
     */
    public function updateShipment(Request $request, Order $order, int $orderShipmentId): JsonResponse
    {
        $data          = $request->all();
        $orderShipment = OrderShipment::query()->where('order_id', $order->id)->findOrFail($orderShipmentId);
        ShipmentService::updateShipment($orderShipment, $data);
        hook_action('admin.order.update_shipment.after', [
            'request_data' => $data,
            'shipment'     => $orderShipment,
        ]);

        return json_success(trans('common.updated_success'));
    }

    public function createShipment(Request $request, Order $order)
    {
        $shipment = ShipmentService::handleShipment(\request('express_code'), \request('express_number'));
        ShipmentService::addShipment($order->id, $shipment);

        hook_action('admin.order.add_shipment.after', $request->all());

        return json_success(trans('common.created_success'));
    }

    public function destroy(Request $request, Order $order)
    {
        $order->delete();
        hook_action('admin.order.destroy.after', $order);

        return json_success(trans('common.deleted_success'));
    }

    public function restore(Request $request)
    {
        $id = $request->id ?? 0;
        Order::withTrashed()->find($id)->restore();

        hook_action('admin.product.restore.after', $id);

        return ['success' => true];
    }

    public function shipping(Request $request)
    {
        $orderIds = $request->get('selected', '');
        $orderId  = $request->get('order_id');
        if (! $orderIds && $orderId) {
            $orderIds = $orderId;
        }
        $orderIds = explode(',', $orderIds);
        $orders   = OrderRepo::filterAll(['order_ids' => $orderIds]);

        $data = [
            'orders' => OrderShippingList::collection($orders)->jsonSerialize(),
        ];

        $data = hook_filter('admin.order.shipping.data', $data);

        return view('admin::pages.orders.shipping', $data);
    }
}
