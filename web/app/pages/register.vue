<script setup lang="ts">
import type { ApiError } from '../utils/api'

const { register } = useAuth()

const email = ref('')
const password = ref('')
const formError = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const isSubmitting = ref(false)

function validate(): string | null {
  if (!email.value.trim()) return 'Email is required.'
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value)) return 'Enter a valid email address.'
  if (!password.value) return 'Password is required.'
  if (password.value.length < 8) return 'Password must be at least 8 characters.'
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
    await register(email.value, password.value)
    await navigateTo('/')
  } catch (error) {
    const apiError = error as ApiError
    formError.value = apiError.message ?? 'Registration failed. Please try again.'
    fieldErrors.value = apiError.errors ?? {}
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <main class="auth-page">
    <h1>Create account</h1>
    <form novalidate @submit.prevent="onSubmit">
      <label>
        Email
        <input v-model="email" type="email" autocomplete="email" />
      </label>
      <p v-if="fieldErrors.email" class="field-error">{{ fieldErrors.email[0] }}</p>

      <label>
        Password
        <input v-model="password" type="password" autocomplete="new-password" />
      </label>
      <p v-if="fieldErrors.password" class="field-error">{{ fieldErrors.password[0] }}</p>

      <p v-if="formError" class="form-error">{{ formError }}</p>

      <button type="submit" :disabled="isSubmitting">
        {{ isSubmitting ? 'Creating account…' : 'Create account' }}
      </button>
    </form>
    <p class="switch-link">
      Already have an account? <NuxtLink to="/login">Log in</NuxtLink>
    </p>
  </main>
</template>

<style scoped>
.auth-page {
  max-width: 360px;
  margin: 4rem auto;
  padding: 2rem;
  font-family: system-ui, sans-serif;
}

label {
  display: block;
  margin-bottom: 0.25rem;
  font-size: 0.9rem;
}

input {
  display: block;
  width: 100%;
  padding: 0.5rem;
  margin-bottom: 0.5rem;
  box-sizing: border-box;
  font-size: 1rem;
}

button {
  width: 100%;
  padding: 0.6rem;
  margin-top: 0.5rem;
  font-size: 1rem;
  cursor: pointer;
}

.field-error,
.form-error {
  color: #c0392b;
  font-size: 0.85rem;
  margin: 0 0 0.5rem;
}

.switch-link {
  margin-top: 1.5rem;
  font-size: 0.9rem;
  text-align: center;
}
</style>
