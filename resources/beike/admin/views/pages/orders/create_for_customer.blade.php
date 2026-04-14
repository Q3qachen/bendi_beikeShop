@extends('admin::layouts.master')

@section('title', __('admin/order.create_for_customer'))

@section('page-title-right')
  <a href="{{ admin_route('orders.index') }}" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left"></i> {{ __('common.return') }}
  </a>
@endsection

@section('content')
<div id="order-for-customer-app" v-cloak>

  {{-- ─── Step 1：选择用户 ─────────────────────────────────────────── --}}
  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title">① 选择用户</h6></div>
    <div class="card-body">
      <div class="row align-items-start">
        <div class="col-md-7">
          <div class="d-flex gap-2 mb-2">
            <el-select
              v-model="selectedCustomerId"
              filterable
              clearable
              placeholder="输入姓名或邮箱筛选用户"
              style="width:100%"
              size="small"
              @change="onSelectCustomer"
              @clear="clearCustomer">
              <el-option
                v-for="c in source.customers"
                :key="c.id"
                :label="c.name + ' (' + c.email + ')'"
                :value="c.id">
              </el-option>
            </el-select>
            <el-button size="small" @click="customerDialog.show = true">新建用户</el-button>
          </div>
          <div v-if="selectedCustomer" class="alert alert-success py-2 mb-0">
            <i class="bi bi-person-check-fill me-1"></i>
            <strong>@{{ selectedCustomer.name }}</strong>
            &nbsp;·&nbsp;@{{ selectedCustomer.email }}
          </div>
        </div>
      </div>
    </div>
  </div>

  {{-- ─── Step 2：添加商品 ────────────────────────────────────────────── --}}
  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title">② 添加商品</h6></div>
    <div class="card-body">
      <div class="d-flex gap-2 mb-3">
        <el-select
          v-model="selectedSkuId"
          filterable
          clearable
          placeholder="输入商品名称或 SKU 筛选"
          style="width:100%"
          size="small"
          @change="onSelectSku">
          <el-option
            v-for="p in source.productOptions"
            :key="p.sku_id"
            :label="p.label + '  [' + p.sku + ']'"
            :value="p.sku_id">
            <div class="d-flex align-items-center justify-content-between">
              <span>
                <img :src="p.image" style="width:24px;height:24px;object-fit:cover;vertical-align:middle" class="border rounded me-1">
                @{{ p.label }}
              </span>
              <small class="text-muted ms-4">@{{ p.price_format }}</small>
            </div>
          </el-option>
        </el-select>
      </div>

      {{-- 已添加商品列表 --}}
      <div v-if="orderProducts.length" class="table-push">
        <table class="table">
          <thead>
            <tr>
              <th>商品</th>
              <th>SKU</th>
              <th>规格</th>
              <th style="width:130px">单价</th>
              <th style="width:130px">数量</th>
              <th class="text-end">小计</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(item, index) in orderProducts" :key="index">
              <td>
                <div class="d-flex align-items-center gap-2">
                  <img :src="item.image" style="width:40px;height:40px;object-fit:cover" class="border rounded">
                  <span>@{{ item.name }}</span>
                </div>
              </td>
              <td><small class="text-muted">@{{ item.sku }}</small></td>
              <td><small class="text-muted">@{{ item.variant_label || '—' }}</small></td>
              <td>
                <el-input-number v-model="item.price" :min="0" :precision="2" :controls="false"
                  size="mini" style="width:110px" @change="onPriceChange"></el-input-number>
              </td>
              <td>
                <el-input-number v-model="item.quantity" :min="1" size="mini" style="width:110px"
                  @change="onQuantityChange"></el-input-number>
              </td>
              <td class="text-end fw-bold">@{{ formatCurrency(item.price * item.quantity) }}</td>
              <td>
                <el-button type="text" size="mini" class="text-danger" @click="removeProduct(index)">
                  <i class="bi bi-x-lg"></i>
                </el-button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else class="text-muted small py-2">暂未添加商品</div>
    </div>
  </div>

  {{-- ─── Step 3：收货信息 ────────────────────────────────────────────── --}}
  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title">③ 收货信息</h6></div>
    <div class="card-body">

      {{-- 已有地址快速选择 --}}
      <div class="mb-3" v-if="selectedCustomer">
        <div v-if="addressLoading" class="text-muted small mb-2">
          <i class="bi bi-hourglass-split me-1"></i>加载地址中...
        </div>
        <div v-else-if="customerAddresses.length" class="d-flex align-items-center gap-2 mb-2">
          <span class="text-muted small text-nowrap">选择已有地址：</span>
          <el-select size="small" style="width:440px" placeholder="选择后自动填入下方表单"
            v-model="selectedAddressId" clearable @change="applyAddress">
            <el-option
              v-for="addr in customerAddresses"
              :key="addr.id"
              :value="addr.id"
              :label="addr.name + ' · ' + addr.address_1 + (addr.city ? ' ' + addr.city : '')">
            </el-option>
          </el-select>
        </div>
        <div v-else class="alert alert-warning py-2 small mb-2">
          <i class="bi bi-exclamation-triangle me-1"></i>该用户暂无收货地址，请手动填写
        </div>
      </div>

      <el-form :model="shippingForm" :rules="shippingRules" ref="shippingForm" label-width="90px">
        <div class="row">
          <div class="col-md-6">
            <el-form-item label="收货人" prop="name">
              <el-input v-model="shippingForm.name" size="small" placeholder="收货人姓名"></el-input>
            </el-form-item>
          </div>
          <div class="col-md-6">
            <el-form-item label="手机号" prop="telephone">
              <el-input v-model="shippingForm.telephone" size="small" placeholder="手机号码"></el-input>
            </el-form-item>
          </div>
        </div>
        <div class="row">
          <div class="col-md-4">
            <el-form-item label="国家" prop="country_id">
              <el-select v-model="shippingForm.country_id" filterable size="small" style="width:100%"
                placeholder="选择国家" @change="onCountryChange">
                <el-option v-for="c in source.countries" :key="c.id" :label="c.name" :value="c.id"></el-option>
              </el-select>
            </el-form-item>
          </div>
          <div class="col-md-4">
            <el-form-item label="省/州" prop="zone_id">
              <el-select v-model="shippingForm.zone_id" filterable size="small" style="width:100%"
                placeholder="选择省/州">
                <el-option v-for="z in source.zones" :key="z.id" :label="z.name" :value="z.id"></el-option>
              </el-select>
            </el-form-item>
          </div>
          <div class="col-md-4">
            <el-form-item label="城市" prop="city">
              <el-input v-model="shippingForm.city" size="small" placeholder="城市"></el-input>
            </el-form-item>
          </div>
        </div>
        <div class="row">
          <div class="col-md-8">
            <el-form-item label="详细地址" prop="address_1">
              <el-input v-model="shippingForm.address_1" size="small" placeholder="街道、楼栋、门牌号"></el-input>
            </el-form-item>
          </div>
          <div class="col-md-4">
            <el-form-item label="邮编">
              <el-input v-model="shippingForm.zipcode" size="small" placeholder="邮政编码"></el-input>
            </el-form-item>
          </div>
        </div>
        <div class="mt-1">
          <el-button size="small" type="primary" plain :loading="loadingShipping"
            @click="loadShippingMethods" :disabled="!canLoadShipping">
            <i class="bi bi-arrow-repeat me-1"></i>查询配送方式
          </el-button>
          <small class="text-muted ms-2" v-if="!canLoadShipping">请先选择用户并添加商品后再查询</small>
        </div>
      </el-form>
    </div>
  </div>

  {{-- ─── Step 4：配送方式 ────────────────────────────────────────────── --}}
  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title">④ 配送方式</h6></div>
    <div class="card-body">
      <div v-if="loadingShipping" class="text-muted"><i class="bi bi-hourglass-split me-1"></i>查询中...</div>
      <div v-else-if="shippingMethods.length === 0" class="text-muted small">暂无可用配送方式，请填写收货信息后点击"查询配送方式"</div>
      <el-radio-group v-else v-model="selectedShippingCode" @change="onShippingChange">
        <div v-for="method in shippingMethods" :key="method.code">
          <div class="mb-2 fw-bold small text-muted">@{{ method.name }}</div>
          <el-radio v-for="quote in method.quotes" :key="quote.code" :label="quote.code" class="mb-2 d-block">
            @{{ quote.name }}
            <span v-if="quote.cost_format" class="ms-2 text-primary">@{{ quote.cost_format }}</span>
          </el-radio>
        </div>
      </el-radio-group>
    </div>
  </div>

  {{-- ─── Step 5：支付方式 ────────────────────────────────────────────── --}}
  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title">⑤ 支付方式</h6></div>
    <div class="card-body">
      <div v-if="source.paymentMethods.length === 0" class="text-muted small">暂无可用支付方式</div>
      <el-radio-group v-else v-model="selectedPaymentCode">
        <el-radio v-for="p in source.paymentMethods" :key="p.code" :label="p.code" class="me-4">
          @{{ p.name }}
        </el-radio>
      </el-radio-group>
    </div>
  </div>

  {{-- ─── Step 6：金额预览 ────────────────────────────────────────────── --}}
  <div class="card mb-4" v-if="totals.length">
    <div class="card-header"><h6 class="card-title">⑥ 金额预览</h6></div>
    <div class="card-body">
      <table class="table table-borderless w-auto ms-auto">
        <tbody>
          <tr v-for="t in totals" :key="t.code">
            <td class="text-end text-muted">@{{ t.title }}</td>
            <td class="text-end fw-bold ps-4">@{{ t.amount_format }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  {{-- ─── 运单号 + 提交 ─────────────────────────────────────────────────── --}}
  <div class="card mb-4">
    <div class="card-body">
      <div class="row">
        <div class="col-md-6">
          <label class="form-label small">运单号</label>
          <el-input v-model="expressNumber" size="small" placeholder="选填，填写后将自动记录到发货信息"></el-input>
        </div>
      </div>
      <div class="mt-3">
        <el-button type="primary" size="medium" :loading="submitting" @click="submitOrder"
          :disabled="!canSubmit">
          <i class="bi bi-check-circle me-1"></i>创建订单
        </el-button>
        <small v-if="!canSubmit" class="text-muted ms-3">请完成用户、商品、收货信息、配送方式、支付方式的选择</small>
      </div>
    </div>
  </div>

  {{-- ─── 新建用户 Dialog ─────────────────────────────────────────────── --}}
  <el-dialog title="新建用户" :visible.sync="customerDialog.show" width="500px"
    @close="resetCustomerDialog">
    <el-form :model="customerDialog.form" :rules="customerDialog.rules" ref="customerDialogForm"
      label-width="90px">
      <el-form-item label="姓名" prop="name">
        <el-input v-model="customerDialog.form.name" size="small" placeholder="用户姓名"></el-input>
      </el-form-item>
      <el-form-item label="邮箱" prop="email">
        <el-input v-model="customerDialog.form.email" size="small" placeholder="登录邮箱"></el-input>
      </el-form-item>
      <el-form-item label="手机号">
        <el-input v-model="customerDialog.form.telephone" size="small" placeholder="手机号（选填）"></el-input>
      </el-form-item>
      <el-form-item label="密码">
        <el-input v-model="customerDialog.form.password" size="small" type="password"
          placeholder="留空则随机生成"></el-input>
      </el-form-item>
      <el-form-item label="用户分组">
        <el-select v-model="customerDialog.form.customer_group_id" size="small" style="width:100%">
          <el-option v-for="g in source.customerGroups" :key="g.id"
            :label="g.description ? g.description.name : g.id" :value="g.id"></el-option>
        </el-select>
      </el-form-item>
    </el-form>
    <span slot="footer">
      <el-button size="small" @click="customerDialog.show = false">取消</el-button>
      <el-button type="primary" size="small" :loading="customerDialog.saving"
        @click="submitCreateCustomer">确认创建</el-button>
    </span>
  </el-dialog>

</div>
@endsection

@push('footer')
<script>
  const _defaultCountryId = @json((int) system_setting('base.country_id'));

  const _urls = {
    storeCustomer:   @json(admin_route('orders.create_for_customer.customers.store')),
    searchProducts:  @json(admin_route('orders.create_for_customer.products.search')),
    shippingMethods: @json(admin_route('orders.create_for_customer.shipping_methods')),
    calculate:       @json(admin_route('orders.create_for_customer.calculate')),
    store:           @json(admin_route('orders.create_for_customer.store')),
    ordersIndex:     @json(admin_route('orders.index')),
    zonesApi(countryId)  { return `countries/${countryId}/zones`; },
    addressesApi(customerId) { return @json(admin_route('orders.create_for_customer.customers.addresses', ['customer_id' => '__ID__'])).replace('__ID__', customerId); },
  };

  let app = new Vue({
    el: '#order-for-customer-app',

    data: {
      // Step 1: Customer
      selectedCustomerId: '',
      selectedCustomer: null,
      customerAddresses: [],
      selectedAddressId: '',
      addressLoading: false,

      // Step 2: Products
      selectedSkuId: '',
      orderProducts: [],   // [{product_id, sku_id, sku, name, image, price, price_format, variant_label, quantity}]

      // Step 3: Shipping info
      shippingForm: {
        name: '',
        telephone: '',
        address_1: '',
        city: '',
        zone_id: '',
        country_id: _defaultCountryId,
        zipcode: '',
      },

      // Step 4: Shipping methods
      shippingMethods: [],
      selectedShippingCode: '',
      selectedShippingName: '',
      loadingShipping: false,

      // Step 5: Payment method
      selectedPaymentCode: '',
      selectedPaymentName: '',

      // Step 6: Totals
      totals: [],

      // 运单号
      expressNumber: '',

      // Submit
      submitting: false,

      // Step 3: Shipping form validation rules
      shippingRules: {
        name:       [{ required: true, message: '请输入收货人姓名', trigger: 'blur' }],
        telephone:  [{ required: true, message: '请输入手机号', trigger: 'blur' }],
        address_1:  [{ required: true, message: '请输入详细地址', trigger: 'blur' }],
        country_id: [{ required: true, message: '请选择国家', trigger: 'change' }],
      },

      // New customer dialog
      customerDialog: {
        show: false,
        saving: false,
        form: {
          name: '',
          email: '',
          telephone: '',
          password: '',
          customer_group_id: '',
        },
        rules: {
          name:  [{ required: true, message: '请输入姓名', trigger: 'blur' }],
          email: [
            { required: true, message: '请输入邮箱', trigger: 'blur' },
            { type: 'email', message: '邮箱格式不正确', trigger: 'blur' },
          ],
        },
      },

      // Source data
      source: {
        paymentMethods: @json($payment_methods ?? []),
        customerGroups: @json($customer_groups ?? []),
        countries: @json($countries ?? []),
        customers: @json($customers ?? []),
        productOptions: @json($product_options ?? []),
        zones: [],
      },
    },

    computed: {
      // 是否满足查询配送方式的前提
      canLoadShipping() {
        return this.selectedCustomer &&
               this.orderProducts.length > 0 &&
               this.shippingForm.address_1;
      },

      // 是否可以提交订单
      canSubmit() {
        return this.selectedCustomer &&
               this.orderProducts.length > 0 &&
               this.shippingForm.name &&
               this.shippingForm.telephone &&
               this.shippingForm.address_1 &&
               this.selectedPaymentCode;
      },

      // 组装给后端的 products 数组
      productsPayload() {
        return this.orderProducts.map(p => ({
          sku_id:   p.sku_id,
          quantity: p.quantity,
          price:    p.price,
        }));
      },

      // 组装地址公共字段
      shippingPayload() {
        return {
          shipping_name:       this.shippingForm.name,
          shipping_telephone:  this.shippingForm.telephone,
          shipping_address_1:  this.shippingForm.address_1,
          shipping_city:       this.shippingForm.city,
          shipping_zone_id:    this.shippingForm.zone_id,
          shipping_country_id: this.shippingForm.country_id,
          shipping_zipcode:    this.shippingForm.zipcode,
        };
      },
    },

    created() {
      this.onCountryChange(this.shippingForm.country_id);
    },

    methods: {

      // ── 省份 ────────────────────────────────────────────────────────

      onCountryChange(countryId) {
        if (!countryId) return;
        this.shippingForm.zone_id = '';
        $http.get(_urls.zonesApi(countryId)).then(res => {
          this.source.zones = res.data.zones || [];
        }).catch(() => {});
      },

      // ── Step 1: Customer ────────────────────────────────────────────

      onSelectCustomer(id) {
        this.selectedCustomer    = this.source.customers.find(c => c.id === id) || null;
        this.customerAddresses   = [];
        this.selectedAddressId   = '';
        this.resetShippingForm();
        this.resetShippingState();
        if (!id) return;
        // 加载该用户的收货地址列表
        this.addressLoading = true;
        $http.get(_urls.addressesApi(id)).then(res => {
          this.customerAddresses = res.data.addresses || [];
          // 自动回填默认地址
          const defaultId   = res.data.default_address_id;
          const defaultAddr = this.customerAddresses.find(a => a.id === defaultId)
                           || this.customerAddresses[0]
                           || null;
          if (defaultAddr) {
            this.selectedAddressId = defaultAddr.id;
            this.applyAddress(defaultAddr.id);
          }
        }).catch(() => {}).finally(() => {
          this.addressLoading = false;
        });
      },

      applyAddress(addressId) {
        if (!addressId) return;
        const addr = this.customerAddresses.find(a => a.id === addressId);
        if (!addr) return;
        this.shippingForm.name       = addr.name       || '';
        this.shippingForm.telephone  = addr.phone      || '';
        this.shippingForm.address_1  = addr.address_1  || '';
        this.shippingForm.city       = addr.city       || '';
        this.shippingForm.country_id = addr.country_id || '';
        this.shippingForm.zipcode    = addr.zipcode    || '';
        // 先加载该国家的省份列表，完成后再赋值 zone_id，避免 onCountryChange 清空 zone_id
        if (addr.country_id) {
          const zoneId = addr.zone_id || '';
          $http.get(_urls.zonesApi(addr.country_id)).then(res => {
            this.source.zones = res.data.zones || [];
            // 等 Vue 将 el-option 列表渲染完毕后再绑定 zone_id，否则 el-select 匹配不到选项
            this.$nextTick(() => {
              this.shippingForm.zone_id = zoneId;
            });
          }).catch(() => {
            this.shippingForm.zone_id = zoneId;
          });
        } else {
          this.shippingForm.zone_id = addr.zone_id || '';
        }
        this.resetShippingState();
      },

      clearCustomer() {
        this.selectedCustomer   = null;
        this.selectedCustomerId = '';
        this.customerAddresses  = [];
        this.selectedAddressId  = '';
        this.resetShippingForm();
        this.resetShippingState();
      },

      submitCreateCustomer() {
        this.$refs.customerDialogForm.validate(valid => {
          if (!valid) return;
          this.customerDialog.saving = true;
          $http.post(_urls.storeCustomer, this.customerDialog.form).then(res => {
            layer.msg(res.message);
            const newCustomer = res.data;
            // 插入下拉列表并按姓名排序
            this.source.customers.push(newCustomer);
            this.source.customers.sort((a, b) => a.name.localeCompare(b.name));
            // 自动选中新建用户（新用户无地址，清空地址状态）
            this.selectedCustomerId = newCustomer.id;
            this.selectedCustomer   = newCustomer;
            this.customerAddresses  = [];
            this.selectedAddressId  = '';
            this.customerDialog.show = false;
            this.resetShippingState();
          }).catch(err => {
            layer.msg(err.responseJSON?.message || '创建失败');
          }).finally(() => {
            this.customerDialog.saving = false;
          });
        });
      },

      resetCustomerDialog() {
        this.$refs.customerDialogForm && this.$refs.customerDialogForm.resetFields();
        this.customerDialog.form = { name: '', email: '', telephone: '', password: '', customer_group_id: '' };
      },

      // ── Step 2: Products ────────────────────────────────────────────

      onSelectSku(skuId) {
        if (!skuId) return;
        const item = this.source.productOptions.find(p => p.sku_id === skuId);
        if (!item) return;

        const existing = this.orderProducts.find(p => p.sku_id === skuId);
        if (existing) {
          existing.quantity += 1;
        } else {
          this.orderProducts.push({
            product_id:    item.product_id,
            sku_id:        item.sku_id,
            sku:           item.sku,
            name:          item.name,
            image:         item.image,
            price:         item.price,
            price_format:  item.price_format,
            variant_label: item.variant_label,
            quantity:      1,
          });
        }
        // 选完后清空下拉，方便连续添加不同商品
        this.$nextTick(() => { this.selectedSkuId = ''; });
        this.resetTotals();
      },

      removeProduct(index) {
        this.orderProducts.splice(index, 1);
        this.resetTotals();
      },

      onQuantityChange() {
        this.resetTotals();
        this.calculateTotals();
      },

      onPriceChange() {
        this.resetTotals();
        this.calculateTotals();
      },

      formatCurrency(amount) {
        // 简单格式化，实际格式由后端 calculate 接口返回
        return parseFloat(amount).toFixed(2);
      },

      // ── Step 4: Shipping methods ────────────────────────────────────

      loadShippingMethods() {
        if (!this.canLoadShipping) return;

        this.$refs.shippingForm.validate(valid => {
          if (!valid) return;

          this.loadingShipping = true;
          this.shippingMethods = [];
          this.selectedShippingCode = '';
          this.selectedShippingName = '';
          this.totals = [];

          const payload = Object.assign({
            customer_id: this.selectedCustomer.id,
            products:    this.productsPayload,
          }, this.shippingPayload);

          $http.post(_urls.shippingMethods, payload).then(res => {
            this.shippingMethods = res.data || [];
            // 自动选中第一个可用方式
            if (this.shippingMethods.length) {
              const firstQuote = this.shippingMethods[0].quotes?.[0];
              if (firstQuote) {
                this.selectedShippingCode = firstQuote.code;
                this.selectedShippingName = firstQuote.name;
                this.calculateTotals();
              }
            }
          }).catch(err => {
            layer.msg(err.responseJSON?.message || '获取配送方式失败');
          }).finally(() => {
            this.loadingShipping = false;
          });
        });
      },

      onShippingChange(code) {
        // 更新配送方式名称
        for (const method of this.shippingMethods) {
          const quote = (method.quotes || []).find(q => q.code === code);
          if (quote) {
            this.selectedShippingName = quote.name;
            break;
          }
        }
        this.calculateTotals();
      },

      // ── Step 6: Calculate totals ────────────────────────────────────

      calculateTotals() {
        if (!this.selectedCustomer || !this.orderProducts.length || !this.selectedShippingCode) return;

        const payload = Object.assign({
          customer_id:          this.selectedCustomer.id,
          products:             this.productsPayload,
          shipping_method_code: this.selectedShippingCode,
          payment_method_code:  this.selectedPaymentCode,
        }, this.shippingPayload);

        $http.post(_urls.calculate, payload).then(res => {
          this.totals = res.data || [];
        }).catch(() => {});
      },

      resetTotals() {
        this.totals = [];
      },

      resetShippingState() {
        this.shippingMethods = [];
        this.selectedShippingCode = '';
        this.selectedShippingName = '';
        this.totals = [];
      },

      resetShippingForm() {
        this.shippingForm.name       = '';
        this.shippingForm.telephone  = '';
        this.shippingForm.address_1  = '';
        this.shippingForm.city       = '';
        this.shippingForm.zone_id    = '';
        this.shippingForm.country_id = _defaultCountryId;
        this.shippingForm.zipcode    = '';
        this.source.zones            = [];
        // 重置后重新加载默认国家的省份，确保省份下拉始终可用
        if (_defaultCountryId) {
          $http.get(_urls.zonesApi(_defaultCountryId)).then(res => {
            this.source.zones = res.data.zones || [];
          }).catch(() => {});
        }
      },

      // ── Submit ───────────────────────────────────────────────────────

      submitOrder() {
        if (!this.canSubmit) return;

        this.$refs.shippingForm.validate(valid => {
          if (!valid) {
            layer.msg('请完整填写收货信息');
            return;
          }

          // 补充支付方式名称
          const paymentMethod = this.source.paymentMethods.find(p => p.code === this.selectedPaymentCode);

          const payload = Object.assign({
            customer_id:          this.selectedCustomer.id,
            products:             this.productsPayload,
            shipping_method_code: this.selectedShippingCode,
            shipping_method_name: this.selectedShippingName,
            payment_method_code:  this.selectedPaymentCode,
            payment_method_name:  paymentMethod?.name || '',
            express_number:       this.expressNumber,
          }, this.shippingPayload);

          this.submitting = true;
          $http.post(_urls.store, payload).then(res => {
            layer.msg(res.message || '订单创建成功');
            setTimeout(() => {
              window.location.href = res.data?.order_url || _urls.ordersIndex;
            }, 800);
          }).catch(err => {
            layer.msg(err.responseJSON?.message || '创建订单失败，请检查填写内容');
          }).finally(() => {
            this.submitting = false;
          });
        });
      },
    },
  });
</script>
@endpush
