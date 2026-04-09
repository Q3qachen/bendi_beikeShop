<?php
/**
 * OrderForCustomerService.php
 *
 * @copyright  2022 beikeshop.com - All Rights Reserved
 * @link       https://beikeshop.com
 * @author     guangda <service@guangda.work>
 * @created    2024-04-08 00:00:00
 * @modified   2024-04-08 00:00:00
 */

namespace Beike\Admin\Services;

use Beike\Models\Cart;
use Beike\Models\CartProduct;
use Beike\Models\Order;
use Beike\Models\ProductSku;
use Beike\Models\Zone;
use Beike\Repositories\AddressRepo;
use Beike\Repositories\CartRepo;
use Beike\Repositories\CustomerRepo;
use Beike\Repositories\OrderRepo;
use Beike\Repositories\PluginRepo;
use Beike\Services\ShippingMethodService;
use Beike\Services\StateMachineService;
use Beike\Shop\Http\Resources\Checkout\PaymentMethodItem;
use Beike\Shop\Services\CartService;
use Beike\Shop\Services\CheckoutService;
use Illuminate\Support\Facades\DB;

class OrderForCustomerService
{
    /**
     * 代客下单 - 创建订单
     *
     * 流程：
     *  1  查找用户
     *  2  创建收货地址
     *  3  清理用户现有购物车
     *  4  将商品写入 CartProduct（selected=true）
     *  5  通过 CheckoutService 初始化购物车与金额计算
     *  6  手动构建 checkoutData（绕过 CheckoutService::shippingRequired 中
     *     依赖 current_customer() 的问题，避免后台上下文时 shipping_address_id 被置 0）
     *  7  调用 OrderRepo::create() 写库
     *  8  触发状态机 → UNPAID
     *  9  finally 块清理临时购物车数据
     *
     * @param  array $data
     * @return Order
     * @throws \Throwable
     */
    public static function createOrder(array $data): Order
    {
        $customerId = (int) ($data['customer_id'] ?? 0);
        if (! $customerId) {
            throw new \Exception('缺少用户 ID');
        }

        $customer = CustomerRepo::find($customerId);
        if (! $customer) {
            throw new \Exception('用户不存在');
        }

        $products = $data['products'] ?? [];
        if (empty($products)) {
            throw new \Exception('请至少添加一个商品');
        }

        $shippingMethodCode = $data['shipping_method_code'] ?? '';
        $shippingMethodName = $data['shipping_method_name'] ?? '';
        $paymentMethodCode  = $data['payment_method_code']  ?? '';
        $paymentMethodName  = $data['payment_method_name']  ?? '';

        if (empty($paymentMethodCode)) {
            throw new \Exception('请选择支付方式');
        }

        // 2. 创建收货地址（同时作为付款地址）
        $address = AddressRepo::create(self::buildAddressData($data, $customer->id));

        try {
            // 3. 清理用户当前购物车（Cart + CartProduct），避免影响正式订单数据
            self::clearCustomerCart($customer->id);

            // 4. 将管理员选择的商品批量写入 CartProduct
            self::fillCustomerCart($customer->id, $products);

            // 5. 创建 CheckoutService（构造函数内部会从 DB 读取 Cart 和已选商品）
            //    由于步骤 3-4 已完成，构造函数不会抛出"空购物车"异常
            $checkout = new CheckoutService($customer);

            // 将 Cart 更新为正确的地址和配送/支付方式
            // CartRepo::createCart 不会覆盖非零的有效 address_id，所以这里直接更新
            $checkout->cart->update([
                'shipping_address_id'  => $address->id,
                'payment_address_id'   => $address->id,
                'shipping_method_code' => $shippingMethodCode,
                'payment_method_code'  => $paymentMethodCode,
            ]);
            // 刷新实例，确保关联关系（shippingAddress / paymentAddress）反映最新数据
            $checkout->cart->refresh();
            $checkout->cart->load(['shippingAddress', 'paymentAddress']);

            // 重置 CartService 静态缓存，确保 initTotalService 读到最新购物车数据
            self::resetCartServiceCache();

            // 初始化 TotalService（基于已更新的 Cart 和商品列表计算各项费用）
            $checkout->initTotalService();
            $totals = $checkout->totalService->getTotals($checkout);

            // 解析方法名称（前端未传时回退到插件查询）
            if (empty($paymentMethodName)) {
                $paymentMethodName = self::resolvePaymentMethodName($paymentMethodCode);
            }
            if (empty($shippingMethodName) && ! empty($shippingMethodCode)) {
                $shippingMethodName = self::resolveShippingMethodName($shippingMethodCode, $checkout);
            }

            // 6. 手动构建 checkoutData
            //    不走 CheckoutService::checkoutData()，原因：该方法内的 shippingRequired()
            //    调用 current_customer()（前台 session），后台上下文始终为 null，
            //    会导致 shipping_address_id 被强制置 0
            $cartList = CartService::list($customer, true);
            $carts    = CartService::reloadData($cartList);

            $checkoutData = [
                'customer' => $customer,
                'current'  => [
                    'shipping_address_id'    => $address->id,
                    'payment_address_id'     => $address->id,
                    'shipping_method_code'   => $shippingMethodCode,
                    'shipping_method_name'   => $shippingMethodName,
                    'payment_method_code'    => $paymentMethodCode,
                    'payment_method_name'    => $paymentMethodName,
                    'guest_shipping_address' => null,
                    'guest_payment_address'  => null,
                    'extra'                  => null,
                ],
                'carts'   => $carts,
                'totals'  => $totals,
                'comment' => $data['comment'] ?? '',
            ];

            // 7. 写库（事务保证原子性）
            DB::beginTransaction();
            try {
                // 7a. 创建订单主记录、订单商品、订单金额明细
                $order = OrderRepo::create($checkoutData);

                // 7b. 通过状态机将订单状态推进至 UNPAID（同时写入 OrderHistory）
                StateMachineService::getInstance($order)->changeStatus(StateMachineService::UNPAID, '', true);

                hook_action('admin.order_for_customer.order_created.after', ['order' => $order, 'customer' => $customer]);

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }

            return $order;

        } finally {
            // 9. 无论成功或失败，始终清理临时购物车数据，不影响用户后续前台下单
            self::clearCustomerCart($customer->id);
        }
    }

    /**
     * 获取可用配送方式列表（AJAX 用）
     *
     * 内部临时构造购物车和地址，查询各配送插件报价后立即清理。
     * 直接调用 ShippingMethodService::getShippingMethods 而非
     * getShippingMethodsForCurrentCart，避免后者依赖 current_customer()。
     *
     * @param  array $data
     * @return array
     * @throws \Exception
     */
    public static function getShippingMethods(array $data): array
    {
        $customer = CustomerRepo::find($data['customer_id'] ?? 0);
        if (! $customer) {
            throw new \Exception('用户不存在');
        }

        $products = $data['products'] ?? [];
        if (empty($products)) {
            return [];
        }

        $tempAddress = AddressRepo::create(self::buildAddressData($data, $customer->id));

        try {
            self::clearCustomerCart($customer->id);
            self::fillCustomerCart($customer->id, $products);

            $checkout = self::buildCheckoutForCalculation(
                $customer,
                $tempAddress->id,
                $data['shipping_method_code'] ?? '',
                $data['payment_method_code']  ?? ''
            );

            // 以目标用户的实际购物车判断是否需要配送，而非 current_customer()
            if (! CartRepo::shippingRequired($customer->id)) {
                return [];
            }

            return ShippingMethodService::getShippingMethods($checkout);

        } finally {
            self::clearCustomerCart($customer->id);
            AddressRepo::delete($tempAddress->id);
        }
    }

    /**
     * 实时计算订单金额预览（AJAX 用）
     *
     * 内部临时构造购物车，通过 TotalService 计算各项费用后立即清理。
     *
     * @param  array $data
     * @return array
     * @throws \Exception
     */
    public static function calculate(array $data): array
    {
        $customer = CustomerRepo::find($data['customer_id'] ?? 0);
        if (! $customer) {
            throw new \Exception('用户不存在');
        }

        $products = $data['products'] ?? [];
        if (empty($products)) {
            return [];
        }

        $tempAddress = AddressRepo::create(self::buildAddressData($data, $customer->id));

        try {
            self::clearCustomerCart($customer->id);
            self::fillCustomerCart($customer->id, $products);

            $checkout = self::buildCheckoutForCalculation(
                $customer,
                $tempAddress->id,
                $data['shipping_method_code'] ?? '',
                $data['payment_method_code']  ?? ''
            );

            return $checkout->totalService->getTotals($checkout);

        } finally {
            self::clearCustomerCart($customer->id);
            AddressRepo::delete($tempAddress->id);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // 私有辅助方法
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * 清理用户购物车（Cart 主表 + CartProduct 商品明细）
     *
     * 必须同时删除 Cart 记录，否则 CartRepo::createCart 会复用旧 Cart，
     * 可能保留过期的 address_id / method_code。
     */
    private static function clearCustomerCart(int $customerId): void
    {
        CartProduct::query()->where('customer_id', $customerId)->delete();
        Cart::query()->where('customer_id', $customerId)->delete();
    }

    /**
     * 将商品列表批量写入 CartProduct（selected=true）
     *
     * 每条记录通过 sku_id 找到对应的 ProductSku，使用 sku.sku 字段作为 product_sku。
     *
     * @param  int   $customerId
     * @param  array $products  [['sku_id' => int, 'quantity' => int], ...]
     * @throws \Exception
     */
    private static function fillCustomerCart(int $customerId, array $products): void
    {
        foreach ($products as $item) {
            $skuId    = (int) ($item['sku_id']   ?? 0);
            $quantity = (int) ($item['quantity']  ?? 1);

            if ($skuId <= 0 || $quantity <= 0) {
                continue;
            }

            $sku = ProductSku::query()->find($skuId);
            if (! $sku) {
                throw new \Exception("SKU ID {$skuId} 不存在");
            }

            CartProduct::query()->create([
                'customer_id' => $customerId,
                'product_id'  => $sku->product_id,
                'product_sku' => $sku->sku,
                'quantity'    => $quantity,
                'selected'    => true,
            ]);
        }
    }

    /**
     * 构建供计算使用的 CheckoutService 实例
     *
     * 步骤：
     *  - CartRepo::createCart 内部会读取已更新的 Cart（已有正确 address_id）
     *  - 更新 Cart 的 address / method 字段并刷新关联
     *  - 初始化 TotalService（基于当前 Cart 和 CartProduct 数据）
     *
     * @param  \Beike\Models\Customer $customer
     * @param  int    $addressId
     * @param  string $shippingMethodCode
     * @param  string $paymentMethodCode
     * @return CheckoutService
     * @throws \Exception
     */
    private static function buildCheckoutForCalculation(
        $customer,
        int $addressId,
        string $shippingMethodCode,
        string $paymentMethodCode
    ): CheckoutService {
        $checkout = new CheckoutService($customer);

        $checkout->cart->update([
            'shipping_address_id'  => $addressId,
            'payment_address_id'   => $addressId,
            'shipping_method_code' => $shippingMethodCode,
            'payment_method_code'  => $paymentMethodCode,
        ]);
        $checkout->cart->refresh();
        $checkout->cart->load(['shippingAddress', 'paymentAddress']);

        self::resetCartServiceCache();
        $checkout->initTotalService();

        return $checkout;
    }

    /**
     * 重置 CartService 静态缓存
     *
     * CartService::$cartList 是静态属性，同一请求内首次调用后即缓存结果。
     * 在后台为目标用户操作购物车后，必须清除缓存，确保 initTotalService
     * 读取到最新的 CartProduct 数据，而非本次请求早期的缓存值。
     */
    private static function resetCartServiceCache(): void
    {
        try {
            $prop = (new \ReflectionClass(CartService::class))->getProperty('cartList');
            $prop->setAccessible(true);
            $prop->setValue(null, null);
        } catch (\ReflectionException $e) {
            // 静态属性访问失败时忽略，不影响主流程
        }
    }

    /**
     * 从请求数据中提取地址字段，映射到 Address 模型的 fillable 字段
     *
     * @param  array $data
     * @param  int   $customerId
     * @return array
     */
    private static function buildAddressData(array $data, int $customerId): array
    {
        $zoneId   = (int) ($data['shipping_zone_id'] ?? 0);
        $zoneName = $zoneId ? (Zone::query()->find($zoneId)?->name ?? '') : '';

        return [
            'customer_id' => $customerId,
            'name'        => $data['shipping_name']      ?? '',
            'phone'       => $data['shipping_telephone'] ?? '',
            'address_1'   => $data['shipping_address_1'] ?? '',
            'address_2'   => '',
            'city'        => $data['shipping_city']      ?? '',
            'zone_id'     => $zoneId,
            'zone'        => $zoneName,
            'country_id'  => (int) ($data['shipping_country_id'] ?? 0),
            'zipcode'     => $data['shipping_zipcode']   ?? '',
        ];
    }

    /**
     * 通过支付方式 code 解析显示名称
     *
     * @param  string $code
     * @return string
     */
    private static function resolvePaymentMethodName(string $code): string
    {
        if (empty($code)) {
            return '';
        }

        $payments = PaymentMethodItem::collection(PluginRepo::getPaymentMethods())->jsonSerialize();
        foreach ($payments as $payment) {
            if ($payment['code'] === $code) {
                return $payment['name'];
            }
        }

        return '';
    }

    /**
     * 通过配送方式 code 解析显示名称
     *
     * 需要调用各配送插件的 getQuotes 方法，依赖已配置好的 CheckoutService 上下文。
     *
     * @param  string          $code
     * @param  CheckoutService $checkout
     * @return string
     */
    private static function resolveShippingMethodName(string $code, CheckoutService $checkout): string
    {
        if (empty($code)) {
            return '';
        }

        try {
            $methods = ShippingMethodService::getShippingMethods($checkout);
            foreach ($methods as $method) {
                foreach ($method['quotes'] ?? [] as $quote) {
                    if ($quote['code'] === $code) {
                        return $quote['name'];
                    }
                }
            }
        } catch (\Exception $e) {
            // 配送插件异常时不影响主流程，名称留空
        }

        return '';
    }
}
