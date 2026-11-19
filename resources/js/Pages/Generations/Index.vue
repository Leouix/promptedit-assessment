<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { formatUsd } from '@/lib/pricing';

const props = defineProps({
    generations: Array,
});

const STATUS_STYLES = {
    pending: 'bg-yellow-100 text-yellow-800',
    queued: 'bg-yellow-100 text-yellow-800',
    processing: 'bg-blue-100 text-blue-800',
    succeeded: 'bg-green-100 text-green-800',
    failed: 'bg-red-100 text-red-800',
};

// Live status: refresh quietly while anything is still in flight.
const timer = ref(null);

const hasOpen = computed(() =>
    props.generations.some(
        (g) => !['succeeded', 'failed'].includes(g.status),
    ),
);

function startPolling() {
    stopPolling();
    timer.value = setInterval(() => {
        if (hasOpen.value) {
            router.reload({
                only: ['generations'],
                preserveScroll: true,
            });
        }
    }, 4000);
}

function stopPolling() {
    if (timer.value) clearInterval(timer.value);
}

onMounted(startPolling);
onBeforeUnmount(stopPolling);
</script>

<template>
    <Head title="History" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">
                Your generations
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-4 sm:px-6 lg:px-8">
                <p
                    v-if="generations.length === 0"
                    class="bg-white p-6 text-center text-gray-500 shadow-sm sm:rounded-lg"
                >
                    Nothing here yet —
                    <a
                        :href="route('generations.create')"
                        class="text-indigo-600 hover:underline"
                        >generate your first image</a
                    >.
                </p>

                <div
                    v-for="g in generations"
                    :key="g.id"
                    class="bg-white shadow-sm sm:rounded-lg"
                >
                    <div class="flex items-start justify-between p-5">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span
                                    class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                    :class="STATUS_STYLES[g.status]"
                                >
                                    {{ g.status }}
                                </span>
                                <span class="text-xs text-gray-400">
                                    {{ g.size }} · {{ g.quality }}
                                </span>
                            </div>
                            <p class="mt-2 truncate text-sm text-gray-800">
                                {{ g.prompt }}
                            </p>

                            <!-- Money -->
                            <div class="mt-2 text-sm text-gray-600">
                                <span>Estimate: {{ formatUsd(Number(g.estimate_cost)) }}</span>
                                <template v-if="g.status === 'succeeded'">
                                    <span class="mx-1 text-gray-300">|</span>
                                    <span>
                                        You paid:
                                        <strong class="text-gray-900">
                                            {{ formatUsd(Number(g.charged_cost)) }}
                                        </strong>
                                    </span>
                                    <span class="text-xs text-gray-400">
                                        (Pixelforge: {{ formatUsd(Number(g.provider_cost)) }})
                                    </span>
                                </template>
                                <template v-else-if="g.status === 'failed'">
                                    <span class="mx-1 text-gray-300">|</span>
                                    <span class="text-gray-500">Free — not charged.</span>
                                </template>
                            </div>

                            <p
                                v-if="g.status === 'failed' && g.error"
                                class="mt-1 text-sm text-red-600"
                            >
                                {{ g.error.message }}
                            </p>
                        </div>

                        <div v-if="g.image_url" class="ms-4 shrink-0">
                            <a :href="g.image_url" target="_blank">
                                <img
                                    :src="g.image_url"
                                    :alt="g.prompt"
                                    class="h-24 w-24 rounded-md object-cover"
                                />
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
