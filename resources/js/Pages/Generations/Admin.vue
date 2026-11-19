<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { formatUsd } from '@/lib/pricing';

const props = defineProps({
    rows: Array,
});
</script>

<template>
    <Head title="Admin report" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">
                Admin report — credits, provider costs, profit
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
                <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <p class="mb-4 text-sm text-gray-500">
                        Days are UTC — the day a generation completed, matching
                        how Pixelforge bills in
                        <code class="text-xs">GET /v1/usage</code>.
                    </p>

                    <p
                        v-if="rows.length === 0"
                        class="text-center text-gray-500"
                    >
                        No completed generations yet.
                    </p>

                    <table
                        v-else
                        class="min-w-full divide-y divide-gray-200 text-sm"
                    >
                        <thead>
                            <tr class="text-left text-xs uppercase text-gray-500">
                                <th class="py-2">Day (UTC)</th>
                                <th class="py-2">User</th>
                                <th class="py-2 text-right">Credits spent</th>
                                <th class="py-2 text-right">Provider cost</th>
                                <th class="py-2 text-right">Profit</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="row in rows" :key="`${row.completed_day}-${row.user_id}`">
                                <td class="py-2">{{ row.completed_day }}</td>
                                <td class="py-2">{{ row.user_name }}</td>
                                <td class="py-2 text-right">
                                    {{ formatUsd(Number(row.credits_spent)) }}
                                </td>
                                <td class="py-2 text-right">
                                    {{ formatUsd(Number(row.provider_cost)) }}
                                </td>
                                <td class="py-2 text-right font-medium">
                                    {{ formatUsd(Number(row.profit)) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
