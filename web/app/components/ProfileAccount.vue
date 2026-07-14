<script setup lang="ts">
import { apiFetch, type ApiError } from '../utils/api'

const { user, fetchUser } = useAuth()

const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const currentPassword = ref('')
const message = ref('')
const formError = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const isSubmitting = ref(false)

onMounted(() => {
  email.value = user.value?.email ?? ''
})

watch(user, (u) => {
  if (u && !email.value) email.value = u.email
})

async function onSubmit(): Promise<void> {
  message.value = ''
  formError.value = ''
  fieldErrors.value = {}

  if (!currentPassword.value) {
    formError.value = 'Enter your current password to save changes.'
    return
  }

  const body: Record<string, unknown> = { current_password: currentPassword.value }
  if (email.value && email.value !== user.value?.email) body.email = email.value
  if (password.value) {
    body.password = password.value
    body.password_confirmation = passwordConfirmation.value
  }

  if (!('email' in body) && !('password' in body)) {
    formError.value = 'Nothing to change.'
    return
  }

  isSubmitting.value = true
  try {
    await apiFetch('/api/user', { method: 'PATCH', body })
    await fetchUser()
    message.value = 'Account updated.'
    password.value = ''
    passwordConfirmation.value = ''
    currentPassword.value = ''
  } catch (error) {
    const apiError = error as ApiError
    formError.value = apiError.message ?? 'Update failed. Please try again.'
    fieldErrors.value = apiError.errors ?? {}
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <section class="rounded-xl border border-slate-800 bg-slate-900 p-6">
    <h2 class="mb-4 font-semibold text-teal-300">Account</h2>
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

      <div class="grid gap-3 sm:grid-cols-2">
        <label class="mb-1 block text-sm text-slate-300">
          New password
          <input
            v-model="password"
            type="password"
            autocomplete="new-password"
            placeholder="Leave blank to keep"
            class="mb-2 mt-1 block w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 placeholder:text-slate-600 focus:border-teal-400 focus:outline-none"
          />
        </label>
        <label class="mb-1 block text-sm text-slate-300">
          Confirm new password
          <input
            v-model="passwordConfirmation"
            type="password"
            autocomplete="new-password"
            class="mb-2 mt-1 block w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-teal-400 focus:outline-none"
          />
        </label>
      </div>
      <p v-if="fieldErrors.password" class="mb-2 text-sm text-rose-400">
        {{ fieldErrors.password[0] }}
      </p>

      <label class="mb-1 block text-sm text-slate-300">
        Current password <span class="text-slate-500">(required to save)</span>
        <input
          v-model="currentPassword"
          type="password"
          autocomplete="current-password"
          class="mb-2 mt-1 block w-full rounded-md border border-slate-700 bg-slate-950 px-3 py-2 text-slate-100 focus:border-teal-400 focus:outline-none"
        />
      </label>
      <p v-if="fieldErrors.current_password" class="mb-2 text-sm text-rose-400">
        {{ fieldErrors.current_password[0] }}
      </p>

      <p v-if="formError" class="mb-2 text-sm text-rose-400">{{ formError }}</p>
      <p v-if="message" class="mb-2 text-sm text-teal-300">{{ message }}</p>

      <button
        type="submit"
        :disabled="isSubmitting"
        class="mt-2 rounded-md bg-teal-500 px-4 py-2 font-semibold text-slate-950 transition hover:bg-teal-400 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-teal-400 disabled:cursor-not-allowed disabled:opacity-50"
      >
        {{ isSubmitting ? 'Saving…' : 'Save changes' }}
      </button>
    </form>
  </section>
</template>
