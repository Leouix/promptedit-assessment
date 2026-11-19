<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { estimateProviderCost, formatUsd } from '@/lib/pricing';

const props = defineProps({
    pricing: Object,
});

const sizes = ['1024x1024', '1536x1024', '1024x1536'];
const qualities = ['low', 'medium', 'high'];

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [],
});

const referenceUrl = ref('');
const referenceError = ref('');

function addReferenceImage() {
    const url = referenceUrl.value.trim();
    if (!/^https?:\/\//i.test(url)) {
        referenceError.value = 'Enter an http(s) image URL.';
        return;
    }
    if (form.reference_images.length >= 4) {
        referenceError.value = 'At most 4 reference images.';
        return;
    }
    if (form.reference_images.includes(url)) {
        referenceError.value = 'That URL is already added.';
        return;
    }
    referenceError.value = '';
    form.reference_images.push(url);
    referenceUrl.value = '';
}

const estimate = computed(() =>
    estimateProviderCost(
        props.pricing,
        form.prompt,
        form.size,
        form.quality,
        form.reference_images.length,
    ),
);

function submit() {
    form.post(route('generations.store'));
}
</script>

<template>
    <Head title="Generate" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold text-gray-800">
                Generate an image
            </h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-3xl sm:px-6 lg:px-8">
                <div class="bg-white p-6 shadow-sm sm:rounded-lg">
                    <form @submit.prevent="submit" class="space-y-5">
                        <div>
                            <label
                                for="prompt"
                                class="block text-sm font-medium text-gray-700"
                                >Prompt</label
                            >
                            <textarea
                                id="prompt"
                                v-model="form.prompt"
                                rows="4"
                                required
                                maxlength="4000"
                                placeholder="A lighthouse on a cliff at dawn, oil painting"
                                class="mt-1 block w-full rounded-md border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                            ></textarea>
                            <p
                                v-if="form.errors.prompt"
                                class="mt-1 text-sm text-red-600"
                            >
                                {{ form.errors.prompt }}
                            </p>
                        </div>

                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label
                                    for="size"
                                    class="block text-sm font-medium text-gray-700"
                                    >Size</label
                                >
                                <select
                                    id="size"
                                    v-model="form.size"
                                    class="mt-1 block w-full rounded-md border-gray-300"
                                >
                                    <option v-for="s in sizes" :key="s" :value="s">
                                        {{ s }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label
                                    for="quality"
                                    class="block text-sm font-medium text-gray-700"
                                    >Quality</label
                                >
                                <select
                                    id="quality"
                                    v-model="form.quality"
                                    class="mt-1 block w-full rounded-md border-gray-300"
                                >
                                    <option
                                        v-for="q in qualities"
                                        :key="q"
                                        :value="q"
                                    >
                                        {{ q }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <!-- Optional reference images -->
                        <div>
                            <label
                                class="block text-sm font-medium text-gray-700"
                                >Reference image URLs
                                <span class="font-normal text-gray-400"
                                    >(optional, up to 4)</span
                                ></label
                            >
                            <div class="mt-1 flex gap-2">
                                <input
                                    v-model="referenceUrl"
                                    type="text"
                                    placeholder="https://example.com/style.png"
                                    class="block w-full rounded-md border-gray-300"
                                    @keyup.enter.prevent="addReferenceImage"
                                />
                                <button
                                    type="button"
                                    @click="addReferenceImage"
                                    class="rounded-md border border-gray-300 bg-white px-3 text-sm text-gray-700 hover:bg-gray-50"
                                >
                                    Add
                                </button>
                            </div>
                            <p
                                v-if="referenceError"
                                class="mt-1 text-sm text-red-600"
                            >
                                {{ referenceError }}
                            </p>
                            <p
                                v-if="form.errors['reference_images.0'] || form.errors['reference_images.1'] || form.errors['reference_images.2'] || form.errors['reference_images.3'] || form.errors.reference_images"
                                class="mt-1 text-sm text-red-600"
                            >
                                {{ form.errors.reference_images ||
                                form.errors['reference_images.0'] }}
                            </p>
                            <ul class="mt-2 space-y-1">
                                <li
                                    v-for="(url, i) in form.reference_images"
                                    :key="url"
                                    class="flex items-center justify-between rounded bg-gray-50 px-2 py-1 text-xs text-gray-600"
                                >
                                    <span class="truncate">{{ url }}</span>
                                    <button
                                        type="button"
                                        @click="form.reference_images.splice(i, 1)"
                                        class="text-red-500 hover:text-red-700"
                                    >
                                        remove
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <!-- Cost estimate before submitting -->
                        <div
                            class="rounded-md bg-gray-50 p-4 text-sm text-gray-700"
                        >
                            <div class="flex justify-between">
                                <span>Estimated cost to you (incl. 25%)</span>
                                <span class="text-base font-semibold text-gray-900">
                                    {{ formatUsd(estimate.userCost) }}
                                </span>
                            </div>
                            <div class="mt-1 flex justify-between text-xs text-gray-500">
                                <span>Est. Pixelforge cost</span>
                                <span>{{ formatUsd(estimate.providerCost) }}</span>
                            </div>
                            <p class="mt-2 text-xs text-gray-500">
                                The actual cost is recorded when the generation
                                finishes: the number of text tokens is only
                                known then (±15% of the estimate), and
                                reasoning tokens vary. Failed generations are
                                free.
                            </p>
                        </div>

                        <p
                            v-if="form.errors.credits"
                            class="text-sm font-medium text-red-600"
                        >
                            {{ form.errors.credits }}
                        </p>

                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-500 disabled:opacity-50"
                        >
                            {{ form.processing ? 'Submitting…' : 'Generate' }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
