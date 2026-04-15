@extends('admin::layouts.master')

@section('title', __('admin/order.create_for_customer'))

@section('page-title-right')
  <a href="{{ admin_route('orders.index') }}" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left"></i> {{ __('common.return') }}
  </a>
@endsection

@section('content')
<div id="order-for-customer-app" v-cloak>

  {{-- ─── Step 1: Select Customer ─────────────────────────────────────────── --}}
  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title">{{ __('admin/order.step_select_customer') }}</h6></div>
    <div class="card-body">
      <div class="row align-items-start">
        <div class="col-md-7">
          <div class="d-flex gap-2 mb-2">
            <el-select
              v-model="selectedCustomerId"
              filterable
              clearable
              placeholder="{{ __('admin/order.input_name_or_email') }}"
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
            <el-button size="small" @click="customerDialog.show = true">{{ __('admin/order.new_customer') }}</el-button>
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

  {{-- ─── Step 2: Add Products ────────────────────────────────────────────── --}}
  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title">{{ __('admin/order.step_add_products') }}</h6></div>
    <div class="card-body">
      <div class="d-flex gap-2 mb-3">
        <el-select
          v-model="selectedSkuId"
          filterable
          clearable
          placeholder="{{ __('admin/order.input_product_name_or_sku') }}"
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
              <th>{{ __('admin/order.product_name') }}</th>
              <th>SKU</th>
              <th>{{ __('admin/order.specification') }}</th>
              <th style="width:130px">{{ __('admin/order.unit_price') }}</th>
              <th style="width:130px">{{ __('admin/order.quantity') }}</th>
              <th class="text-end">{{ __('admin/order.subtotal') }}</th>
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
      <div v-else class="text-muted small py-2">{{ __('admin/order.no_products_added') }}</div>
    </div>
  </div>

  {{-- ─── Step 3: Shipping Information ────────────────────────────────────────────── --}}
  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title">{{ __('admin/order.step_shipping_info') }}</h6></div>
    <div class="card-body">

      {{-- Existing address quick select --}}
      <div class="mb-3" v-if="selectedCustomer">
        <div v-if="addressLoading" class="text-muted small mb-2">
          <i class="bi bi-hourglass-split me-1"></i>{{ __('admin/order.loading_address') }}
        </div>
        <div v-else-if="customerAddresses.length" class="d-flex align-items-center gap-2 mb-2">
          <span class="text-muted small text-nowrap">{{ __('admin/order.select_existing_address') }}</span>
          <el-select size="small" style="width:440px" placeholder="{{ __('admin/order.address_auto_fill') }}"
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
          <i class="bi bi-exclamation-triangle me-1"></i>{{ __('admin/order.no_shipping_address') }}
        </div>
      </div>

      <el-form :model="shippingForm" :rules="shippingRules" ref="shippingForm" label-width="90px">
        <div class="row">
          <div class="col-md-6">
            <el-form-item label="{{ __('admin/order.receiver_name') }}" prop="name">
              <el-input v-model="shippingForm.name" size="small" placeholder="{{ __('admin/order.receiver_name_placeholder') }}"></el-input>
            </el-form-item>
          </div>
          <div class="col-md-6">
            <el-form-item label="{{ __('admin/order.phone_number') }}" prop="telephone">
              <el-input v-model="shippingForm.telephone" size="small" placeholder="{{ __('admin/order.phone_placeholder') }}"></el-input>
            </el-form-item>
          </div>
        </div>
        <div class="row">
          <div class="col-md-4">
            <el-form-item label="{{ __('admin/order.country') }}" prop="country_id">
              <el-select v-model="shippingForm.country_id" filterable size="small" style="width:100%"
                placeholder="{{ __('admin/order.select_country') }}" @change="onCountryChange">
                <el-option v-for="c in source.countries" :key="c.id" :label="c.name" :value="c.id"></el-option>
              </el-select>
            </el-form-item>
          </div>
          <div class="col-md-4">
            <el-form-item label="{{ __('admin/order.state_province') }}" prop="zone_id">
              <el-select v-model="shippingForm.zone_id" filterable size="small" style="width:100%"
                placeholder="{{ __('admin/order.select_state') }}">
                <el-option v-for="z in source.zones" :key="z.id" :label="z.name" :value="z.id"></el-option>
              </el-select>
            </el-form-item>
          </div>
          <div class="col-md-4">
            <el-form-item label="{{ __('admin/order.city') }}" prop="city">
              <el-input v-model="shippingForm.city" size="small" placeholder="{{ __('admin/order.city_placeholder') }}"></el-input>
            </el-form-item>
          </div>
        </div>
        <div class="row">
          <div class="col-md-8">
            <el-form-item label="{{ __('admin/order.detailed_address') }}" prop="address_1">
              <el-input v-model="shippingForm.address_1" size="small" placeholder="{{ __('admin/order.address_placeholder') }}"></el-input>
            </el-form-item>
          </div>
          <div class="col-md-4">
            <el-form-item label="{{ __('admin/order.zipcode') }}">
              <el-input v-model="shippingForm.zipcode" size="small" placeholder="{{ __('admin/order.zipcode_placeholder') }}"></el-input>
            </el-form-item>
          </div>
        </div>
        <div class="mt-1">
          <el-button size="small" type="primary" plain :loading="loadingShipping"
            @click="loadShippingMethods" :disabled="!canLoadShipping">
            <i class="bi bi-arrow-repeat me-1"></i>{{ __('admin/order.query_shipping_methods') }}
          </el-button>
          <small class="text-muted ms-2" v-if="!canLoadShipping">{{ __('admin/order.please_select_customer_and_products') }}</small>
        </div>
      </el-form>
    </div>
  </div>

  {{-- ─── Step 4: Shipping Method ────────────────────────────────────────────── --}}
  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title">{{ __('admin/order.step_shipping_method') }}</h6></div>
    <div class="card-body">
      <div v-if="loadingShipping" class="text-muted"><i class="bi bi-hourglass-split me-1"></i>{{ __('admin/order.loading_address') }}</div>
      <div v-else-if="shippingMethods.length === 0" class="text-muted small">{{ __('admin/order.no_shipping_methods') }}，{{ __('admin/order.fill_shipping_info_first') }}</div>
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

  {{-- ─── Step 5: Payment Method ────────────────────────────────────────────── --}}
  <div class="card mb-4">
    <div class="card-header"><h6 class="card-title">{{ __('admin/order.step_payment_method') }}</h6></div>
    <div class="card-body">
      <div v-if="source.paymentMethods.length === 0" class="text-muted small">{{ __('admin/order.no_payment_methods') }}</div>
      <el-radio-group v-else v-model="selectedPaymentCode">
        <el-radio v-for="p in source.paymentMethods" :key="p.code" :label="p.code" class="me-4">
          @{{ p.name }}
        </el-radio>
      </el-radio-group>
    </div>
  </div>

  {{-- ─── Step 6: Amount Preview ────────────────────────────────────────────── --}}
  <div class="card mb-4" v-if="totals.length">
    <div class="card-header"><h6 class="card-title">{{ __('admin/order.step_amount_preview') }}</h6></div>
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

  {{-- ─── Tracking Number + Submit ─────────────────────────────────────────────────── --}}
  <div class="card mb-4">
    <div class="card-body">
      <div class="row">
        <div class="col-md-6">
          <label class="form-label small">{{ __('admin/order.tracking_number') }}</label>
          <el-input v-model="expressNumber" size="small" placeholder="{{ __('admin/order.tracking_number_placeholder') }}"></el-input>
        </div>
      </div>
      <div class="mt-3">
        <el-button type="primary" size="medium" :loading="submitting" @click="submitOrder"
          :disabled="!canSubmit">
          <i class="bi bi-check-circle me-1"></i>{{ __('admin/order.create_order') }}
        </el-button>
        <small v-if="!canSubmit" class="text-muted ms-3">{{ __('admin/order.complete_all_fields') }}</small>
      </div>
    </div>
  </div>

  {{-- ─── New Customer Dialog ─────────────────────────────────────────────── --}}
  <el-dialog title="{{ __('admin/order.new_customer_title') }}" :visible.sync="customerDialog.show" width="500px"
    @close="resetCustomerDialog">
    <el-form :model="customerDialog.form" :rules="customerDialog.rules" ref="customerDialogForm"
      label-width="90px">
      <el-form-item label="{{ __('admin/order.customer_name') }}" prop="name">
        <el-input v-model="customerDialog.form.name" size="small" placeholder="{{ __('admin/order.customer_name_placeholder') }}"></el-input>
      </el-form-item>
      <el-form-item label="{{ __('admin/order.email_address') }}" prop="email">
        <el-input v-model="customerDialog.form.email" size="small" placeholder="{{ __('admin/order.email_placeholder') }}"></el-input>
      </el-form-item>
      <el-form-item label="{{ __('admin/order.phone_number') }}">
        <el-input v-model="customerDialog.form.telephone" size="small" placeholder="{{ __('admin/order.phone_optional') }}"></el-input>
      </el-form-item>
      <el-form-item label="{{ __('admin/order.password') }}">
        <el-input v-model="customerDialog.form.password" size="small" type="password"
          placeholder="{{ __('admin/order.password_placeholder') }}"></el-input>
      </el-form-item>
      <el-form-item label="{{ __('admin/order.customer_group') }}">
        <el-select v-model="customerDialog.form.customer_group_id" size="small" style="width:100%">
          <el-option v-for="g in source.customerGroups" :key="g.id"
            :label="g.description ? g.description.name : g.id" :value="g.id"></el-option>
        </el-select>
      </el-form-item>
    </el-form>
    <span slot="footer">
      <el-button size="small" @click="customerDialog.show = false">{{ __('admin/order.cancel') }}</el-button>
      <el-button type="primary" size="small" :loading="customerDialog.saving"
        @click="submitCreateCustomer">{{ __('admin/order.confirm_create') }}</el-button>
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
        name:       [{ required: true, message: '{{ __('admin/order.input_receiver_name') }}', trigger: 'blur' }],
        telephone:  [{ required: true, message: '{{ __('admin/order.input_phone') }}', trigger: 'blur' }],
        address_1:  [{ required: true, message: '{{ __('admin/order.input_address') }}', trigger: 'blur' }],
        country_id: [{ required: true, message: '{{ __('admin/order.select_country_required') }}', trigger: 'change' }],
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
          name:  [{ required: true, message: '{{ __('admin/order.input_customer_name') }}', trigger: 'blur' }],
          email: [
            { required: true, message: '{{ __('admin/order.input_email') }}', trigger: 'blur' },
            { type: 'email', message: '{{ __('admin/order.invalid_email') }}', trigger: 'blur' },
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
      // Whether shipping methods can be queried
      canLoadShipping() {
        return this.selectedCustomer &&
               this.orderProducts.length > 0 &&
               this.shippingForm.address_1;
      },

      // Whether order can be submitted
      canSubmit() {
        return this.selectedCustomer &&
               this.orderProducts.length > 0 &&
               this.shippingForm.name &&
               this.shippingForm.telephone &&
               this.shippingForm.address_1 &&
               this.selectedPaymentCode;
      },

      // Products array for backend
      productsPayload() {
        return this.orderProducts.map(p => ({
          sku_id:   p.sku_id,
          quantity: p.quantity,
          price:    p.price,
        }));
      },

      // Common address fields
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

      // ── State/Province ────────────────────────────────────────────────────────

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
        // Load customer's shipping addresses
        this.addressLoading = true;
        $http.get(_urls.addressesApi(id)).then(res => {
          this.customerAddresses = res.data.addresses || [];
          // Auto-fill default address
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
        // Load state list first, then assign zone_id to avoid onCountryChange clearing it
        if (addr.country_id) {
          const zoneId = addr.zone_id || '';
          $http.get(_urls.zonesApi(addr.country_id)).then(res => {
            this.source.zones = res.data.zones || [];
            // Wait for Vue to render el-option list before binding zone_id
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
            // Insert into dropdown and sort by name
            this.source.customers.push(newCustomer);
            this.source.customers.sort((a, b) => a.name.localeCompare(b.name));
            // Auto-select new customer (no address, clear address state)
            this.selectedCustomerId = newCustomer.id;
            this.selectedCustomer   = newCustomer;
            this.customerAddresses  = [];
            this.selectedAddressId  = '';
            this.customerDialog.show = false;
            this.resetShippingState();
          }).catch(err => {
            layer.msg(err.responseJSON?.message || '{{ __('common.failed') }}');
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
            // Auto-select first available method
            if (this.shippingMethods.length) {
              const firstQuote = this.shippingMethods[0].quotes?.[0];
              if (firstQuote) {
                this.selectedShippingCode = firstQuote.code;
                this.selectedShippingName = firstQuote.name;
                this.calculateTotals();
              }
            }
          }).catch(err => {
            layer.msg(err.responseJSON?.message || '{{ __('common.failed') }}');
          }).finally(() => {
            this.loadingShipping = false;
          });
        });
      },

      onShippingChange(code) {
        // Update shipping method name
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
        // Reload states for default country after reset
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
            layer.msg('{{ __('admin/order.incomplete_shipping_info') }}');
            return;
          }

          // Get payment method name
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
            layer.msg(res.message || '{{ __('admin/order.create_order_success') }}');
            setTimeout(() => {
              window.location.href = res.data?.order_url || _urls.ordersIndex;
            }, 800);
          }).catch(err => {
            layer.msg(err.responseJSON?.message || '{{ __('admin/order.create_order_failed') }}');
          }).finally(() => {
            this.submitting = false;
          });
        });
      },
    },
  });
</script>
@endpush
