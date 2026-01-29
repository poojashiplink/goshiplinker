<x-layout>

    {{-- Page title --}}
    <x-slot name="title">Weight Discrepancies</x-slot>

    {{-- Breadcrumbs --}}
    <x-slot name="breadcrumbs">
      Weight Discrepancies
    </x-slot>

    {{-- Page header --}}
    <x-slot name="page_header_title">
        <h1 class="page-header-title">Weight Discrepancies</h1>
    </x-slot>
    <x-slot name="headerbuttons">
        <div class="col-sm-auto">
            <a href="javascript:history.back()" class="btn btn-light btn-sm"> <i class="bi bi-chevron-left"></i> {{ __('message.back') }} </a>
        </div>
        @if(Request::segment(1) =='admin') 
            <div class="col-sm-auto">     
                <a class="btn btn-primary btn-sm" href="{{ route('admin.weight-discrepancies.upload-form') }}"><i class="bi bi-upload me-1"></i>Weight Discrepancies</a>          
            </div>
        @endif
    </x-slot>
    {{-- Main content --}}
    <x-slot name="main">

        {{-- Success alert --}}
        @if(session('success'))
            <div class="alert alert-soft-success alert-dismissible" role="alert">
                {!! session('success') !!}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        @endif

        <div class="card">
            <div class="card-header">
                <h4 class="card-title">Weight Discrepancy List</h4>
            </div>

            <div class="card-body">
                {{-- Filters --}}
                <form method="GET" class="row g-3 align-items-end mb-4">
                    <div class="col-sm-3 ms-auto">
                        <select name="status" class="form-select">
                            <option value="">All Status</option>
                            <option value="new" {{ request('status')=='new' ? 'selected' : '' }}>New</option>
                            <option value="auto_accepted" {{ request('status')=='auto_accepted' ? 'selected' : '' }}>Auto Accepted</option>
                            <option value="accepted" {{ request('status')=='accepted' ? 'selected' : '' }}>Accepted</option>
                            <option value="dispute_raised" {{ request('status')=='dispute_raised' ? 'selected' : '' }}>Dispute Raised</option>
                            <option value="dispute_accepted" {{ request('status')=='dispute_accepted' ? 'selected' : '' }}>Dispute Accepted</option>
                            <option value="dispute_rejected" {{ request('status')=='dispute_rejected' ? 'selected' : '' }}>Dispute Rejected</option>                           
                            <option value="closed" {{ request('status')=='closed' ? 'selected' : '' }}>Closed</option>
                        </select>
                    </div>

                    <div class="col-sm-auto">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-filter"></i> Filter
                        </button>
                        <a href="{{ url()->current() }}" class="btn btn-light btn-sm">
                            Reset
                        </a>
                    </div>
                </form>
                <div class="table-responsive">
                    <table id="datatable"
                        class="table table-lg table-borderless table-thead-bordered table-nowrap table-align-middle card-table">

                        <thead class="thead-light">
                            <tr>
                                <th>Order Details</th>
                                <th>Product Details</th>
                                <th>Applied Weight</th>
                                <th>Courier Weight</th>
                                <th>Charged Weight</th>
                                <th>Excess Weight & Charge</th>
                                <th>Shorter Image</th>
                                <th>Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>

                        <tbody>
                            @forelse($discrepancies as $discrepancy)
                                <tr>
                                    {{-- Discrepancy details --}}
                                    <td>                                       
                                        <strong>Order Id:</strong> <a href="{{ route('order_view',$discrepancy->order->id) }}" target="__blank">{{ $discrepancy->order->vendor_order_number ?? '-' }}</a><br>
                                        <strong>AWB:</strong> {{ $discrepancy->tracking_number ?? '-' }}<br>
                                        <strong>Courier:</strong> {{ $discrepancy->shipment->courier->name ?? '-' }}<br>
                                        <small class="text-muted">
                                            Updated on: {{ $discrepancy->updated_at->format('d M Y | h:i A') }}
                                        </small>
                                    </td>

                                    {{-- Product details --}}
                                    <td>
                                        @php
                                            $products = $discrepancy->shipment->order->orderProducts ?? collect();
                                            $first = $products->first();
                                        @endphp

                                        @if($first)
                                            {{ $first->product_name }}<br>
                                            <small>SKU: {{ $first->sku }}</small><br>
                                            <small>Qty: {{ $first->quantity }}</small>

                                            @if($products->count() > 1)
                                                <br>
                                                <small class="text-primary">
                                                    +{{ $products->count() - 1 }} more product(s)
                                                </small>
                                            @endif
                                        @else
                                            -
                                        @endif
                                    </td>

                                    {{-- Applied weight --}}
                                    <td>
                                        <strong>{{ $discrepancy->applied_weight }} Kg</strong><br>
                                        <small class="text-muted">Seller declared</small>
                                    </td>

                                    {{-- Courier weight --}}
                                    <td>
                                        <strong>{{ $discrepancy->courier_weight }} Kg</strong><br>
                                        <small class="text-muted">Courier measured</small>
                                    </td>

                                    {{-- Charged weight --}}
                                    <td>
                                        @php
                                            $chargedSlab = ceil($discrepancy->courier_weight / 0.5) * 0.5;
                                        @endphp
                                        <strong>{{ number_format($chargedSlab, 2) }} Kg</strong>
                                    </td>

                                    {{-- Excess --}}
                                    <td>
                                        <strong>{{ $discrepancy->difference_weight }} Kg</strong><br>
                                        <span class="text-danger">
                                            ₹{{ number_format($discrepancy->extra_charge, 2) }}
                                        </span>
                                    </td>
                                    <td>
                                        @if($discrepancy->sorting_machine_image)
                                        <a href="{{ $discrepancy->sorting_machine_image }}" target="_blank">
                                            View Image
                                        </a>
                                        @endif
                                    </td>

                                    {{-- Status --}}
                                    <td>
                                        <span class="badge bg-{{ $statusColors[$discrepancy->status] ?? 'secondary' }}">
                                            {{ strtoupper(str_replace('_', ' ', $discrepancy->status)) }}
                                        </span>
                                    </td>

                                    {{-- Action --}}
                                    <td class="text-end">
                                        <a href=""
                                           class="btn btn-sm btn-outline-primary">
                                            View History
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center text-muted">
                                        No weight discrepancies found
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Pagination --}}
                <div class="mt-3">
                    {{ $discrepancies->links() }}
                </div>

            </div>
        </div>

    </x-slot>

</x-layout>
