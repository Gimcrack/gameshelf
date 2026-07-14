<script setup lang="ts">
import type { ApiError } from '../utils/api'

const { login } = useAuth()

const email = ref('')
const password = ref('')
const formError = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const isSubmitting = ref(false)

function validate(): string | null {
  if (!email.value.trim()) return 'Email is required.'
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) return 'Enter a valid email address.'
  if (!password.value) return 'Password is required.'
  return null
}

async function onSubmit(): Promise<void> {
  formError.value = ''
  fieldErrors.value = {}

  const validationError = validate()
  if (validationError) {
    formError.value = validationError
    return
  }

  isSubmitting.value = true
  try {
    await login(email.value, password.value)
    await navigateTo('/')
  } catch (error) {
    const apiError = error as ApiError
    formError.value = apiError.message ?? 'Login failed. Please try again.'
    fieldErrors.value = apiError.errors ?? {}
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <main class="mx-auto mt-16 max-w-sm rounded-xl border border-slate-800 bg-slate-900 p-8">
    <h1 class="mb-6 text-xl font-bold tracking-tight">
      Log in to Game<span class="text-teal-400">Shelf</span>
    </h1>
    <form novalidate @submit.prevent="onSubmit">
      <label class="mb-1 block text-sm text-slate-300">
        Email
        <input
          v-model="email"
          type="email"
          autocomplete="email"
          class="mb-2 mt-1 block w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-teal-400 focus:outline-none"
        />
      </label>
      <p v-if="fieldErrors.email" class="mb-2 text-sm text-rose-400">{{ fieldErrors.email[0] }}</p>

      <label class="mb-1 block text-sm text-slate-300">
        Password
        <input
          v-model="password"
          type="password"
          autocomplete="current-password"
          class="mb-2 mt-1 block w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-teal-400 focus:outline-none"
        />
      </label>
      <p v-if="fieldErrors.password" class="mb-2 text-sm text-rose-400">
        {{ fieldErrors.password[0] }}
      </p>

      <p v-if="formError" class="mb-2 text-sm text-rose-400">{{ formError }}</p>

      <button
        type="submit"
        :disabled="isSubmitting"
        class="mt-2 w-full rounded-md bg-teal-500 px-4 py-2 font-semibold text-slate-950 transition hover:bg-teal-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-400 disabled:cursor-not-allowed disabled:opacity-50"
      >
        {{ isSubmitting ? 'Logging in…' : 'Log in' }}
      </button>
    </form>
    <p class="mt-6 text-center text-sm text-slate-400">
      Need an account?
      <NuxtLink to="/register" class="text-teal-400 hover:text-teal-300">Register</NuxtLink>
    </p>
  </main>
</template>
