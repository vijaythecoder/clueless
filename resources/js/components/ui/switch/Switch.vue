<template>
    <button
        type="button"
        role="switch"
        :aria-checked="modelValue"
        @click="toggle"
        v-bind="$attrs"
    >
        <slot />
    </button>
</template>

<script setup lang="ts">
import { defineEmits, defineProps } from 'vue';

// Props
const props = defineProps<{
    modelValue: boolean;
}>();

// Emits
const emit = defineEmits<{
    'update:modelValue': [value: boolean];
}>();

// Methods
const toggle = () => {
    emit('update:modelValue', !props.modelValue);
};
</script>