<x-layout>
    <div class="max-w-xl">
        <a href="{{ route('ims.receipts.index') }}" class="text-xs font-semibold text-ink-muted hover:text-ink">&larr; {{ __('ims.back') }}</a>
        <h1 class="font-display text-lg font-bold mt-1 mb-5">{{ __('ims.create_title') }}</h1>

        <form method="POST" action="{{ route('ims.receipts.store') }}" enctype="multipart/form-data"
              class="bg-surface border border-border rounded-xl p-6 space-y-4">
            @csrf

            <div>
                <label for="doc_no" class="block text-sm font-medium mb-1">{{ __('ims.field_doc_no') }}</label>
                <input id="doc_no" name="doc_no" type="text" maxlength="64" value="{{ old('doc_no') }}"
                       class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                @error('doc_no') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="fiscal_year" class="block text-sm font-medium mb-1">{{ __('ims.field_fiscal_year') }}</label>
                    <input id="fiscal_year" name="fiscal_year" type="number" min="2500" max="2700" value="{{ old('fiscal_year') }}"
                           class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                    @error('fiscal_year') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="purchase_round" class="block text-sm font-medium mb-1">{{ __('ims.field_round') }}</label>
                    <input id="purchase_round" name="purchase_round" type="text" maxlength="32" value="{{ old('purchase_round') }}"
                           class="w-full rounded-lg border border-border px-3 py-2 text-sm">
                    @error('purchase_round') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="pdf" class="block text-sm font-medium mb-1">{{ __('ims.field_pdf') }}</label>
                <input id="pdf" name="pdf" type="file" accept="application/pdf" class="w-full text-sm">
                @error('pdf') <p class="text-xs text-danger mt-1">{{ $message }}</p> @enderror
            </div>

            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" name="import_report" value="1" class="mt-1" @checked(old('import_report', true))>
                <span>{{ __('ims.field_import_report') }}</span>
            </label>

            <button type="submit" class="rounded-lg bg-accent hover:bg-accent-strong text-white text-sm font-semibold px-4 py-2.5">
                {{ __('ims.btn_create') }}
            </button>
        </form>
    </div>
</x-layout>
