<?php
/**
 * OrderForCustomerController.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2024-04-08 00:00:00
 * @modified   2024-04-08 00:00:00
 */

namespace Beike\Admin\Http\Controllers;

use Beike\Admin\Services\CustomerService;
use Beike\Admin\Services\OrderForCustomerService;
use Beike\Models\Address;
use Beike\Models\Customer;
use Beike\Models\Product;
use Beike\Models\ProductSku;
use Beike\Repositories\AddressRepo;
use Beike\Repositories\CountryRepo;
use Beike\Repositories\CustomerGroupRepo;
use Beike\Repositories\CustomerRepo;
use Beike\Repositories\PluginRepo;
use Beike\Repositories\ProductRepo;
use Beike\Shop\Http\Resources\Checkout\PaymentMethodItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderForCustomerController extends Controller
{
    /**
     * 代客下单页面
     *
     * @param Request $request
     * @return mixed
     * @throws \Exception
     */
    public function create(Request $request)
    {
        // 所有上架商品的 SKU 扁平列表，供前端下拉选择
        $products        = ProductRepo::getBuilder(['active' => 1])->whereHas('masterSku')->get();
        $productOptions  = $products->flatMap(function (Product $product) {
            $productName = $product->description->name ?? $product->name ?? '';
            $image       = image_resize($product->images[0] ?? '');
            return $product->skus->map(function (ProductSku $sku) use ($product, $productName, $image) {
                $variant = $sku->getVariantLabel();
                return [
                    'sku_id'        => $sku->id,
                    'product_id'    => $product->id,
                    'label'         => $variant ? "{$productName} - {$variant}" : $productName,
                    'name'          => $productName,
                    'sku'           => $sku->sku,
                    'price'         => $sku->price,
                    'price_format'  => currency_format($sku->price),
                    'variant_label' => $variant,
                    'image'         => $image,
                    'quantity'      => $sku->quantity,
                ];
            });
        })->values();

        $data = [
            'payment_methods' => PaymentMethodItem::collection(PluginRepo::getPaymentMethods())->jsonSerialize(),
            'customer_groups' => CustomerGroupRepo::list(),
            'countries'       => CountryRepo::listEnabled(),
            'customers'       => Customer::query()->select(['id', 'name', 'email'])->orderBy('name')->get(),
            'product_options' => $productOptions,
        ];
        $data = hook_filter('admin.order_for_customer.create.data', $data);

        return view('admin::pages.orders.create_for_customer', $data);
    }

    /**
     * 提交代客下单
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $requestData = $request->only([
            'customer_id',
            'products',
            'shipping_name',
            'shipping_telephone',
            'shipping_address_1',
            'shipping_city',
            'shipping_zone_id',
            'shipping_country_id',
            'shipping_zipcode',
            'shipping_method_code',
            'shipping_method_name',
            'payment_method_code',
            'payment_method_name',
            'comment',
        ]);

        try {
            $order = OrderForCustomerService::createOrder($requestData);

            hook_action('admin.order_for_customer.store.after', ['order' => $order]);

            return json_success(trans('common.created_success'), [
                'order_id'  => $order->id,
                'order_url' => admin_route('orders.show', $order->id),
            ]);
        } catch (\Exception $e) {
            return json_fail($e->getMessage());
        }
    }

    /**
     * 获取指定用户的收货地址列表（AJAX）
     * 选择用户后自动回填默认地址使用
     *
     * @param Request $request
     * @param int     $customerId
     * @return JsonResponse
     */
    public function getCustomerAddresses(Request $request, int $customerId): JsonResponse
    {
        $customer = CustomerRepo::find($customerId);
        if (! $customer) {
            return json_fail('用户不存在');
        }

        $addresses = Address::query()
            ->where('customer_id', $customerId)
            ->get(['id', 'name', 'phone', 'country_id', 'zone_id', 'zone', 'city', 'address_1', 'zipcode']);

        return json_success(trans('common.get_success'), [
            'addresses'          => $addresses,
            'default_address_id' => (int) ($customer->address_id ?? 0),
        ]);
    }

    /**
     * 搜索用户（AJAX）
     * 按姓名或邮箱模糊匹配，最多返回 15 条
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function searchCustomers(Request $request): JsonResponse
    {
        $keyword = trim($request->get('keyword', ''));

        $customers = Customer::query()
            ->where(function ($query) use ($keyword) {
                $query->where('name', 'like', "%{$keyword}%")
                    ->orWhere('email', 'like', "%{$keyword}%");
            })
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->limit(15)
            ->get(['id', 'name', 'email']);

        return json_success(trans('common.get_success'), $customers);
    }

    /**
     * 快速创建新用户（AJAX）
     * 复用现有 CustomerService::create，保持数据一致性
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function storeCustomer(Request $request): JsonResponse
    {
        $requestData = $request->only(['name', 'email', 'telephone', 'password', 'customer_group_id']);

        if (empty($requestData['name'])) {
            return json_fail(trans('validation.required', ['attribute' => trans('customer.name')]));
        }
        if (empty($requestData['email'])) {
            return json_fail(trans('validation.required', ['attribute' => trans('customer.email')]));
        }
        if (Customer::query()->where('email', $requestData['email'])->exists()) {
            return json_fail(trans('shop/login.email_registered'));
        }
        if (empty($requestData['customer_group_id'])) {
            $requestData['customer_group_id'] = system_setting('base.default_customer_group_id');
        }
        if (empty($requestData['password'])) {
            $requestData['password'] = '';
        }

        try {
            $customer = CustomerService::create($requestData);

            hook_action('admin.order_for_customer.store_customer.after', ['customer' => $customer]);

            return json_success(trans('common.created_success'), [
                'id'    => $customer->id,
                'name'  => $customer->name,
                'email' => $customer->email,
            ]);
        } catch (\Exception $e) {
            return json_fail($e->getMessage());
        }
    }

    /**
     * 搜索商品及 SKU 信息（AJAX）
     * 按商品名称、SKU 编码模糊匹配，只返回上架商品
     *
     * @param Request $request
     * @return JsonResponse
     * @throws \Exception
     */
    public function searchProducts(Request $request): JsonResponse
    {
        $keyword = trim($request->get('keyword', ''));

        if (empty($keyword)) {
            return json_success(trans('common.get_success'), []);
        }

        $products = ProductRepo::getBuilder(['keyword' => $keyword, 'active' => 1])
            ->whereHas('masterSku')
            ->limit(20)
            ->get();

        $data = $products->map(function (Product $product) {
            $skus = $product->skus->map(function (ProductSku $sku) {
                return [
                    'id'            => $sku->id,
                    'sku'           => $sku->sku,
                    'price'         => $sku->price,
                    'price_format'  => currency_format($sku->price),
                    'quantity'      => $sku->quantity,
                    'variant_label' => $sku->getVariantLabel(),
                    'is_default'    => $sku->is_default,
                ];
            })->values();

            $images = $product->images ?? [];

            return [
                'id'    => $product->id,
                'name'  => $product->description->name ?? '',
                'image' => image_resize($images[0] ?? ''),
                'skus'  => $skus,
            ];
        });

        return json_success(trans('common.get_success'), $data);
    }

    /**
     * 根据收货地址动态获取可用配送方式（AJAX）
     * 内部临时写入购物车数据，获取报价后立即清理
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getShippingMethods(Request $request): JsonResponse
    {
        $requestData = $request->only([
            'customer_id',
            'products',
            'shipping_country_id',
            'shipping_zone_id',
            'shipping_city',
            'shipping_address_1',
            'shipping_zipcode',
        ]);

        try {
            $shippingMethods = OrderForCustomerService::getShippingMethods($requestData);

            return json_success(trans('common.get_success'), $shippingMethods);
        } catch (\Exception $e) {
            return json_fail($e->getMessage());
        }
    }

    /**
     * 实时计算订单金额预览（AJAX）
     * 内部临时写入购物车数据，计算完成后立即清理
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function calculate(Request $request): JsonResponse
    {
        $requestData = $request->only([
            'customer_id',
            'products',
            'shipping_country_id',
            'shipping_zone_id',
            'shipping_city',
            'shipping_address_1',
            'shipping_zipcode',
            'shipping_method_code',
            'payment_method_code',
        ]);

        try {
            $totals = OrderForCustomerService::calculate($requestData);

            return json_success(trans('common.get_success'), $totals);
        } catch (\Exception $e) {
            return json_fail($e->getMessage());
        }
    }
}
