import { useMutation, useQuery } from '@tanstack/react-query'
import { useForm, useWatch } from 'react-hook-form'
import { Link, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { Bot, ShieldCheck, Wallet } from 'lucide-react'
import { useEffect, useState } from 'react'
import { PasswordChecklist, PasswordVisibilityToggle } from '../../components/forms.jsx'
import { ProfileField } from '../../components/profile.jsx'
import { LoadingState } from '../../components/states.jsx'
import { API_URL, api, demoUsers, roleRoutes } from '../../lib/api.js'
import { apiErrorMessage } from '../../lib/errors.js'
import { getSession } from '../../lib/session.js'
import { PASSWORD_HELP, blockSpaceKey, stripSpaces, validateEmail, validatePassword } from '../../lib/validation.js'



function GoogleIcon(props) {
  return (
    <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true" {...props}>
      <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.9c1.7-1.57 2.7-3.87 2.7-6.62Z" />
      <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.9-2.26c-.8.54-1.84.86-3.06.86-2.35 0-4.34-1.59-5.05-3.72H.9v2.33A9 9 0 0 0 9 18Z" />
      <path fill="#FBBC05" d="M3.95 10.7A5.4 5.4 0 0 1 3.67 9c0-.59.1-1.17.28-1.7V4.97H.9A9 9 0 0 0 0 9c0 1.45.35 2.83.9 4.03l3.05-2.33Z" />
      <path fill="#EA4335" d="M9 3.58c1.32 0 2.51.46 3.44 1.35l2.58-2.58C13.46.89 11.43 0 9 0A9 9 0 0 0 .9 4.97l3.05 2.33C4.66 5.17 6.65 3.58 9 3.58Z" />
    </svg>
  )
}

function VerificationNotice({ email, lead }) {
  const [sent, setSent] = useState(false)
  const resend = useMutation({
    mutationFn: async () => (await api.post('/email/resend', { email })).data,
    onSuccess: () => setSent(true),
  })
  return (
    <div className="verification-notice">
      <p>{lead}</p>
      <p className="helper-text">
        We sent a verification link to <strong>{email}</strong>. Open it to activate your account, then come back and log in.
      </p>
      {sent && <p className="helper-text verification-sent">Verification email sent again -- check your inbox and spam folder.</p>}
      <div className="success-actions">
        <button type="button" className="ghost" onClick={() => resend.mutate()} disabled={resend.isPending || !email}>
          {resend.isPending ? 'Sending...' : 'Resend Verification Email'}
        </button>
        <Link className="button" to="/login">Back to Login</Link>
      </div>
      {resend.isError && <p className="error">Could not resend the verification email. Please try again.</p>}
    </div>
  )
}

export function LoginPage() {
  const { register, handleSubmit, formState: { errors } } = useForm({ defaultValues: { email: '', password: '' } })
  // Strip whitespace from the password as it's typed/pasted, exactly like the
  // registration form -- see blockSpaceKey/stripSpaces above.
  const passwordField = register('password')
  const navigate = useNavigate()
  const location = useLocation()
  const [searchParams] = useSearchParams()
  const session = getSession()
  const [unverifiedEmail, setUnverifiedEmail] = useState(searchParams.get('resend_email') || null)
  const [showPassword, setShowPassword] = useState(false)
  useEffect(() => {
    if (session?.role) {
      navigate(roleRoutes[session.role] || '/', { replace: true })
    }
  }, [session, navigate])
  const login = useMutation({
    mutationFn: async (values) => {
      try {
        const { data } = await api.post('/auth/login', values)
        return data
      } catch (err) {
        if (err.response) {
          throw new Error(apiErrorMessage(err, 'Invalid email or password.'), { cause: err })
        }
        // No response at all (backend unreachable) -- fall back to the
        // built-in demo accounts (LGU/Super Admin) so the app stays usable
        // for a quick look without a running API, same as before.
        const user = demoUsers[values.email]
        if (!user) throw new Error(apiErrorMessage(err), { cause: err })
        return { user, token: `demo-${user.role}` }
      }
    },
    onSuccess: ({ user, token }) => {
      localStorage.setItem('fishmarket_user', JSON.stringify(user))
      localStorage.setItem('fishmarket_token', token)
      window.location.replace(location.state?.from || roleRoutes[user.role] || '/')
    },
    onError: (err) => {
      if (err.cause?.response?.data?.unverified) {
        setUnverifiedEmail(err.cause.response.data.email)
      }
    },
  })

  if (unverifiedEmail) {
    return (
      <AuthCard title="Verify Your Email" subtitle="One more step before you can log in.">
        <VerificationNotice email={unverifiedEmail} lead="Please verify your email address before logging in." />
      </AuthCard>
    )
  }

  return (
    <AuthCard title="Login" subtitle="One account gateway for all AbaiMarket roles.">
      <form onSubmit={handleSubmit((v) => login.mutate({ email: (v.email || '').trim(), password: stripSpaces(v.password) }))} className="form">
        <input {...register('email', { validate: (value) => validateEmail(value) || true })} placeholder="Email" />
        <input
          {...passwordField}
          type={showPassword ? 'text' : 'password'}
          placeholder="Password"
          onKeyDown={blockSpaceKey}
          onChange={(e) => { e.target.value = stripSpaces(e.target.value); passwordField.onChange(e) }}
        />
        <PasswordVisibilityToggle shown={showPassword} onToggle={() => setShowPassword(!showPassword)} noun="password" />
        {errors.email && <p className="error">{errors.email.message}</p>}
        <p className="helper-text"><Link to="/forgot-password">Forgot password?</Link></p>
        <button type="submit" disabled={login.isPending}>{login.isPending ? 'Logging in...' : 'Login'}</button>
        {login.error && <p className="error">{login.error.message}</p>}
        {searchParams.get('google_error') && <p className="error">Google sign-in didn't go through. Please try again or use your email and password.</p>}
      </form>
      <div className="auth-divider"><span>or</span></div>
      <a className="ghost full google-button" href={`${API_URL}/auth/google/redirect`}>
        <GoogleIcon /> Continue with Google
      </a>
    </AuthCard>
  )
}

// Step 1 of "forgot password": request the emailed link. Works for every role,
// including accounts created with Google (the reset gives them a password).
// The backend answers identically for unknown emails, so this page always
// shows the same confirmation.
export function ForgotPasswordPage() {
  const { register, handleSubmit, formState: { errors } } = useForm({ defaultValues: { email: '' } })
  const [sentTo, setSentTo] = useState(null)
  const sendLink = useMutation({
    mutationFn: async (email) => {
      try {
        return (await api.post('/auth/forgot-password', { email })).data
      } catch (err) {
        throw new Error(apiErrorMessage(err, 'Could not send the reset link. Please try again.'), { cause: err })
      }
    },
    onSuccess: (_data, email) => setSentTo(email),
  })

  if (sentTo) {
    return (
      <AuthCard title="Check Your Email" subtitle="Password reset requested.">
        <p className="helper-text">
          If <strong>{sentTo}</strong> has an AbaiMarket account, we sent it a link to reset your password. The link expires in 60 minutes -- check your spam folder too.
        </p>
        <div className="success-actions">
          <button type="button" className="ghost" onClick={() => sendLink.mutate(sentTo)} disabled={sendLink.isPending}>
            {sendLink.isPending ? 'Sending...' : 'Send Again'}
          </button>
          <Link className="button" to="/login">Back to Login</Link>
        </div>
        {sendLink.isError && <p className="error">{sendLink.error.message}</p>}
      </AuthCard>
    )
  }

  return (
    <AuthCard title="Forgot Password" subtitle="Enter your account email and we'll send you a reset link.">
      <form onSubmit={handleSubmit((v) => sendLink.mutate((v.email || '').trim()))} className="form">
        <input {...register('email', { validate: (value) => validateEmail(value) || true })} placeholder="Email" />
        {errors.email && <p className="error">{errors.email.message}</p>}
        <p className="helper-text">Signed up with Google? You can use this to set a password as well.</p>
        <button type="submit" disabled={sendLink.isPending}>{sendLink.isPending ? 'Sending...' : 'Send Reset Link'}</button>
        {sendLink.error && <p className="error">{sendLink.error.message}</p>}
      </form>
      <p className="helper-text"><Link to="/login">Back to Login</Link></p>
    </AuthCard>
  )
}

// Step 2: the page the emailed link opens (?token=...&email=...).
export function ResetPasswordPage() {
  const [searchParams] = useSearchParams()
  const token = searchParams.get('token')
  const email = searchParams.get('email')
  const { register, handleSubmit, getValues, control, formState: { errors } } = useForm({ defaultValues: { password: '', password_confirmation: '' } })
  // Live values drive the requirement checklist as the user types.
  const [password, confirmation] = useWatch({ control, name: ['password', 'password_confirmation'] })
  const [showPasswords, setShowPasswords] = useState(false)
  const passwordField = register('password', { validate: (value) => validatePassword(value) || true })
  const confirmField = register('password_confirmation', {
    validate: (value) => value === getValues('password') || 'The passwords do not match.',
  })
  const reset = useMutation({
    mutationFn: async (values) => {
      try {
        return (await api.post('/auth/reset-password', { token, email, ...values })).data
      } catch (err) {
        throw new Error(apiErrorMessage(err, 'Could not reset your password. Please try again.'), { cause: err })
      }
    },
  })

  if (!token || !email) {
    return (
      <AuthCard title="Invalid Link" subtitle="This password reset link is incomplete.">
        <p className="helper-text">Open the link exactly as it appears in the email, or request a new one.</p>
        <div className="success-actions">
          <Link className="button" to="/forgot-password">Request a New Link</Link>
        </div>
      </AuthCard>
    )
  }

  if (reset.isSuccess) {
    return (
      <AuthCard title="Password Reset" subtitle="You're all set.">
        <p className="helper-text">{reset.data?.message || 'Your password has been reset.'}</p>
        <div className="success-actions">
          <Link className="button" to="/login">Go to Login</Link>
        </div>
      </AuthCard>
    )
  }

  const passwordInputProps = (field) => ({
    ...field,
    type: showPasswords ? 'text' : 'password',
    autoComplete: 'new-password',
    onKeyDown: blockSpaceKey,
    onChange: (e) => { e.target.value = stripSpaces(e.target.value); field.onChange(e) },
  })

  return (
    <AuthCard title="Reset Password" subtitle={`Choose a new password for ${email}.`}>
      <form onSubmit={handleSubmit((v) => reset.mutate(v))} className="form">
        <ProfileField label="New password">
          <input {...passwordInputProps(passwordField)} />
        </ProfileField>
        {errors.password && <p className="error">{errors.password.message}</p>}
        <ProfileField label="Confirm new password">
          <input {...passwordInputProps(confirmField)} />
        </ProfileField>
        {errors.password_confirmation && <p className="error">{errors.password_confirmation.message}</p>}
        <PasswordChecklist password={password} confirmation={confirmation} />
        <PasswordVisibilityToggle shown={showPasswords} onToggle={() => setShowPasswords(!showPasswords)} />
        <button type="submit" disabled={reset.isPending}>{reset.isPending ? 'Saving...' : 'Reset Password'}</button>
        {reset.error && (
          <p className="error">
            {reset.error.message} <Link to="/forgot-password">Request a new link</Link>
          </p>
        )}
      </form>
    </AuthCard>
  )
}

export function RegisterPage() {
  const [searchParams] = useSearchParams()
  // Set when someone used the Google button on the login page with an email
  // we have no account for: the backend can't guess Buyer vs Seller, so it
  // sends them here to choose before the account is created.
  const googleRoleRequired = searchParams.get('google_role_required')
  const googleEmail = searchParams.get('email')
  const { register, handleSubmit, watch, getValues, setError, clearErrors, formState: { errors } } = useForm({ defaultValues: { role: 'buyer', municipality_id: '' } })
  const role = watch('role')
  const isSeller = role === 'seller'
  const passwordField = register('password', {
    validate: (value) => validatePassword(value) || true,
  })
  const [showPassword, setShowPassword] = useState(false)
  const municipalitiesQuery = useQuery({
    queryKey: ['municipalities'],
    queryFn: async () => (await api.get('/municipalities')).data,
    retry: false,
    placeholderData: [],
  })
  // An unreachable API, a 404 and a genuinely empty table all used to render
  // the same thing here -- a select with no options -- which reads as "there
  // are no municipalities" and hides the real cause. Seller registration is
  // impossible without this list, so say which of the three it is.
  const municipalities = municipalitiesQuery.data || []
  const municipalityIssue = municipalitiesQuery.isError
    ? "Couldn't load the municipality list. The API may be unreachable -- check that the backend is running and that VITE_API_URL points at it."
    : (!municipalitiesQuery.isFetching && !municipalities.length
      ? 'No municipalities are set up yet. An administrator needs to add them before sellers can register.'
      : null)
  const [registeredEmail, setRegisteredEmail] = useState(null)
  const registerUser = useMutation({
    mutationFn: async (values) => {
      // Buyers/Farmers don't have a municipality -- never send the field
      // for them, even if a stale value lingers from switching roles.
      const payload = { ...values, email: (values.email || '').trim() }
      if (payload.role !== 'seller') delete payload.municipality_id
      try {
        return (await api.post('/auth/register', payload)).data
      } catch (err) {
        throw new Error(apiErrorMessage(err, 'Could not create your account. Please try again.'), { cause: err })
      }
    },
    onSuccess: (data) => setRegisteredEmail(data.user?.email || null),
  })

  const beginGoogleRegistration = () => {
    const values = getValues()
    const params = new URLSearchParams({ registration: '1', role: values.role || 'buyer' })

    if (values.role === 'seller') {
      if (!values.municipality_id) {
        setError('municipality_id', { type: 'required', message: "Please select your hatchery's municipality." })
        return
      }
      params.set('municipality_id', values.municipality_id)
    } else {
      clearErrors('municipality_id')
    }

    window.location.assign(`${API_URL}/auth/google/redirect?${params.toString()}`)
  }

  if (registeredEmail) {
    return (
      <AuthCard title="Check Your Email" subtitle="You're almost there.">
        <VerificationNotice email={registeredEmail} lead="Your AbaiMarket account has been created." />
      </AuthCard>
    )
  }

  return (
    <AuthCard title="Register" subtitle="Registration is available only for buyers and sellers.">
      {googleRoleRequired && (
        <p className="helper-text">
          <strong>Almost there — one more step.</strong>{' '}
          {googleEmail ? `There's no AbaiMarket account for ${googleEmail} yet. ` : "You don't have an AbaiMarket account yet. "}
          Pick <strong>Buyer / Fish Farmer</strong> or <strong>Seller / Hatchery</strong> below, then press
          {' '}<strong>Continue with Google</strong> again to finish creating your account.
        </p>
      )}
      <form onSubmit={handleSubmit((v) => registerUser.mutate(v))} className="form">
        <input {...register('name')} placeholder="Full name / Hatchery name" />
        <input {...register('email', { validate: (value) => validateEmail(value) || true })} placeholder="Email" />
        {errors.email && <p className="error">{errors.email.message}</p>}
        <input
          {...passwordField}
          type={showPassword ? 'text' : 'password'}
          placeholder="Password"
          onKeyDown={blockSpaceKey}
          onChange={(e) => { e.target.value = stripSpaces(e.target.value); passwordField.onChange(e) }}
        />
        <p className="helper-text">{PASSWORD_HELP}</p>
        {errors.password && <p className="error">{errors.password.message}</p>}
        <PasswordVisibilityToggle shown={showPassword} onToggle={() => setShowPassword(!showPassword)} noun="password" />
        <select {...register('role')}><option value="buyer">Buyer / Fish Farmer</option><option value="seller">Seller / Hatchery</option></select>
        {isSeller && (
          <>
            <select {...register('municipality_id', { required: isSeller })} defaultValue="" disabled={!municipalities.length}>
              <option value="" disabled>
                {municipalitiesQuery.isFetching && !municipalities.length ? 'Loading municipalities...' : 'Select municipality'}
              </option>
              {municipalities.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
            </select>
            {municipalityIssue && <p className="error">{municipalityIssue}</p>}
            {errors.municipality_id && <p className="error">Please select your hatchery's municipality.</p>}
          </>
        )}
        <button type="submit" disabled={registerUser.isPending}>{registerUser.isPending ? 'Creating account...' : 'Create Account'}</button>
        {registerUser.error && <p className="error">{registerUser.error.message}</p>}
      </form>
      <div className="auth-divider"><span>or</span></div>
      <button type="button" className="ghost full google-button" onClick={beginGoogleRegistration}>
        <GoogleIcon /> Continue with Google as {isSeller ? 'Seller / Hatchery' : 'Buyer / Fish Farmer'}
      </button>
    </AuthCard>
  )
}

export function GoogleCallbackPage() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const [failed, setFailed] = useState(false)

  useEffect(() => {
    const token = searchParams.get('token')
    if (!token) {
      navigate('/login?google_error=1', { replace: true })
      return
    }
    localStorage.setItem('fishmarket_token', token)
    api.get('/auth/me')
      .then(({ data: user }) => {
        localStorage.setItem('fishmarket_user', JSON.stringify(user))
        window.location.replace(roleRoutes[user.role] || '/')
      })
      .catch(() => {
        localStorage.removeItem('fishmarket_token')
        setFailed(true)
        setTimeout(() => navigate('/login?google_error=1', { replace: true }), 1500)
      })
    // Runs once: exchanges the one-time token in the URL for the session.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return (
    <main className="auth-page">
      <section className="result-card">
        {failed ? (
          <>
            <p className="eyebrow">Google Sign-In</p>
            <h1>Sign-in failed</h1>
            <p>Redirecting you back to login...</p>
          </>
        ) : (
          <LoadingState label="Finishing Google sign-in..." />
        )}
      </section>
    </main>
  )
}

const AUTH_BENEFITS = [
  [ShieldCheck, 'LGU-verified sellers in every municipality'],
  [Wallet, 'Secure PayMongo escrow checkout'],
  [Bot, 'Gemini AI farming assistant built in'],
]

function AuthCard({ title, subtitle, children }) {
  return (
    <main className="auth-page">
      <div className="auth-layout">
        <section className="auth-brand-panel">
          <Link className="brand" to="/"><img className="brand-mark" src="/logo-mark.png" alt="" width="38" height="38" />AbaiMarket</Link>
          <h2>Fresh fingerlings, verified hatcheries, one marketplace.</h2>
          <ul className="auth-benefits">
            {AUTH_BENEFITS.map(([Icon, text]) => (
              <li key={text}><Icon size={18} />{text}</li>
            ))}
          </ul>
        </section>
        <section className="auth-card">
          <p className="eyebrow">AbaiMarket Access</p>
          <h1>{title}</h1>
          <p>{subtitle}</p>
          {children}
        </section>
      </div>
    </main>
  )
}