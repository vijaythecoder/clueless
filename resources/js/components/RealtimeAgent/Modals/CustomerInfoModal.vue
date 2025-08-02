<template>
    <div v-if="showModal" class="bg-opacity-50 fixed inset-0 z-50 flex items-center justify-center bg-black">
        <div class="mx-4 w-full max-w-md rounded-lg border border-gray-200 bg-white p-6 shadow-lg dark:border-gray-700 dark:bg-gray-800">
            <h2 class="mb-4 text-lg font-semibold">Customer Information (Optional)</h2>

            <div class="space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-600 dark:text-gray-400">Customer Name</label>
                    <input
                        v-model="customerName"
                        type="text"
                        class="w-full rounded-lg border border-gray-200 px-3 py-2 focus:border-transparent focus:ring-2 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                        placeholder="John Smith"
                    />
                </div>

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-600 dark:text-gray-400">Company</label>
                    <input
                        v-model="customerCompany"
                        type="text"
                        class="w-full rounded-lg border border-gray-200 px-3 py-2 focus:border-transparent focus:ring-2 focus:ring-blue-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white"
                        placeholder="Acme Corp"
                    />
                </div>
            </div>

            <div class="mt-6 flex gap-3">
                <button
                    @click="startWithInfo"
                    class="flex-1 rounded-lg bg-blue-600 px-4 py-2 text-white transition-colors hover:bg-blue-700"
                >
                    Start Call
                </button>
                <button
                    @click="skip"
                    class="flex-1 rounded-lg bg-gray-100 px-4 py-2 text-gray-800 transition-colors hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600"
                >
                    Skip
                </button>
            </div>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useRealtimeAgentStore } from '@/stores/realtimeAgent';

// Emit events
const emit = defineEmits<{
    startWithInfo: [{ name: string; company: string }];
    skip: [];
}>();

// Store
const realtimeStore = useRealtimeAgentStore();

// Computed
const showModal = computed(() => realtimeStore.showCustomerModal);
const customerName = computed({
    get: () => realtimeStore.customerInfo.name,
    set: (value) => realtimeStore.setCustomerInfo({ 
        ...realtimeStore.customerInfo, 
        name: value 
    })
});
const customerCompany = computed({
    get: () => realtimeStore.customerInfo.company,
    set: (value) => realtimeStore.setCustomerInfo({ 
        ...realtimeStore.customerInfo, 
        company: value 
    })
});

// Methods
const startWithInfo = () => {
    emit('startWithInfo', {
        name: customerName.value,
        company: customerCompany.value
    });
    realtimeStore.setShowCustomerModal(false);
};

const skip = () => {
    emit('skip');
    realtimeStore.setShowCustomerModal(false);
};
</script>