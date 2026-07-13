import { defineNuxtRouteMiddleware, navigateTo } from '#app'
import { useAuthToken, restoreTokenFromStorage } from '../utils/authState'

const PUBLIC_ROUTES = new Set(['/login', '/register'])

export default defineNuxtRouteMiddleware((to) => {
  restoreTokenFromStorage()

  const token = useAuthToken()
  const isPublicRoute = PUBLIC_ROUTES.has(to.path)

  if (!token.value && !isPublicRoute) {
    return navigateTo('/login')
  }

  if (token.value && isPublicRoute) {
    return navigateTo('/')
  }
})
