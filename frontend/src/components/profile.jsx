import { useMutation, useQuery } from '@tanstack/react-query'
import { CalendarDays, Camera, Check, KeyRound, Mail, MapPin, ShieldCheck, UserRound } from 'lucide-react'
import { useRef, useState } from 'react'
import { PasswordChecklist, PasswordVisibilityToggle } from './forms.jsx'
import { RoleBadge } from './ui.jsx'
import { api, queryClient } from '../lib/api.js'
import { DEFAULT_AVATAR_IMAGE, DEFAULT_COVER_IMAGE, IMAGE_UPLOAD_ACCEPT } from '../lib/constants.js'
import { formatMonthYear, roleLabel } from '../lib/format.js'
import { getSession, updateSessionUser } from '../lib/session.js'
import { blockSpaceKey, stripSpaces, validatePassword } from '../lib/validation.js'



export function ProfileHeader({
  name, email, badges, meta = [], actions,
  picture, pictureUploading, onPictureUpload, onPictureRemove, pictureError,
  cover, coverUploading, onCoverUpload, onCoverRemove, coverError,
}) {
  const pictureInput = useRef(null)
  const coverInput = useRef(null)
  const [picturePreview, setPicturePreview] = useState(null)
  const [coverPreview, setCoverPreview] = useState(null)
  const hasCover = Boolean(onCoverUpload)

  // Shows the chosen file immediately while it uploads.
  const pickFile = (setPreview, upload) => (e) => {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    setPreview(URL.createObjectURL(file))
    upload(file)
  }

  return (
    <section className={`card profile-hero${hasCover ? ' profile-hero-with-cover' : ''}`}>
      {hasCover && (
        <div className="profile-hero-cover">
          <img src={(coverUploading && coverPreview) || cover || DEFAULT_COVER_IMAGE} alt="Farm cover photo" />
          <div className="profile-hero-cover-actions">
            <button type="button" className="profile-pill-button" onClick={() => coverInput.current?.click()} disabled={coverUploading}>
              <Camera size={15} aria-hidden="true" />
              {coverUploading ? 'Uploading...' : cover ? 'Change cover' : 'Add cover photo'}
            </button>
            {cover && onCoverRemove && (
              <button type="button" className="profile-pill-button" onClick={onCoverRemove} disabled={coverUploading}>Remove</button>
            )}
          </div>
          <input ref={coverInput} type="file" accept={IMAGE_UPLOAD_ACCEPT} hidden onChange={pickFile(setCoverPreview, onCoverUpload)} />
        </div>
      )}
      <div className="profile-hero-body">
        <div className="profile-hero-avatar">
          <img src={(pictureUploading && picturePreview) || picture || DEFAULT_AVATAR_IMAGE} alt="Your profile picture" />
          {onPictureUpload && (
            <>
              <button type="button" className="profile-avatar-button" onClick={() => pictureInput.current?.click()} disabled={pictureUploading} aria-label="Change profile picture">
                <Camera size={16} aria-hidden="true" />
              </button>
              <input ref={pictureInput} type="file" accept={IMAGE_UPLOAD_ACCEPT} hidden onChange={pickFile(setPicturePreview, onPictureUpload)} />
            </>
          )}
        </div>
        <div className="profile-hero-info">
          <div className="profile-hero-name">
            <h2>{name}</h2>
            {badges}
          </div>
          <p className="profile-hero-meta">
            {email && <span><Mail size={14} aria-hidden="true" />{email}</span>}
            {meta.filter(Boolean).map(([Icon, text]) => <span key={text}><Icon size={14} aria-hidden="true" />{text}</span>)}
          </p>
          {onPictureUpload && (
            <div className="profile-hero-photo-links">
              <button type="button" className="profile-link-button" onClick={() => pictureInput.current?.click()} disabled={pictureUploading}>
                {pictureUploading ? 'Uploading...' : picture ? 'Change photo' : 'Add a profile photo'}
              </button>
              {picture && onPictureRemove && (
                <button type="button" className="profile-link-button profile-link-muted" onClick={onPictureRemove} disabled={pictureUploading}>Remove photo</button>
              )}
            </div>
          )}
          {(pictureError || coverError) && <p className="error">{pictureError || coverError}</p>}
        </div>
        {actions && <div className="profile-hero-actions">{actions}</div>}
      </div>
    </section>
  )
}

export function ProfileCard({ icon: Icon, title, description, children }) {
  return (
    <section className="card profile-card">
      <header className="profile-card-head">
        {Icon && <span className="profile-card-icon"><Icon size={18} aria-hidden="true" /></span>}
        <div>
          <h3>{title}</h3>
          {description && <p className="helper-text">{description}</p>}
        </div>
      </header>
      {children}
    </section>
  )
}

export function ProfileField({ label, hint, wide = false, children }) {
  return (
    <label className={`profile-field${wide ? ' profile-field-wide' : ''}`}>
      <span className="profile-field-label">{label}</span>
      {children}
      {hint && <span className="profile-field-hint">{hint}</span>}
    </label>
  )
}

export function ProfileSaveBar({ dirty, saving, success, error, onSave, onDiscard }) {
  if (!dirty && !saving && !error) {
    return success
      ? <div className="profile-save-bar profile-save-bar-saved" role="status"><Check size={16} aria-hidden="true" />Your profile is saved.</div>
      : null
  }
  return (
    <div className="profile-save-bar" role="region" aria-label="Unsaved changes">
      <p>{error ? <span className="profile-save-error">{error}</span> : saving ? 'Saving your changes...' : 'You have unsaved changes.'}</p>
      <div className="profile-save-bar-actions">
        <button type="button" className="ghost" onClick={onDiscard} disabled={saving}>Discard</button>
        <button type="button" onClick={onSave} disabled={saving}>{saving ? 'Saving...' : 'Save changes'}</button>
      </div>
    </div>
  )
}

export function BuyerSettingsForm({ user, buyerProfile, onSave, saving, success, error }) {
  const initialValues = {
    name: user.name || '',
    email: user.email || '',
    phone: user.phone || '',
    address: buyerProfile?.address || '',
    bio: buyerProfile?.bio || '',
  }
  // `saved` is the last known server state; the save bar compares against it.
  const [saved, setSaved] = useState(initialValues)
  const [form, setForm] = useState(initialValues)
  const dirty = JSON.stringify(form) !== JSON.stringify(saved)
  const setField = (key) => (e) => setForm({ ...form, [key]: e.target.value })

  const uploadPicture = useMutation({
    mutationFn: async (file) => {
      const formData = new FormData()
      formData.append('photo', file)
      return (await api.post('/buyer/profile/picture', formData)).data
    },
    onSuccess: (updatedUser) => {
      updateSessionUser({ profile_picture: updatedUser.profile_picture })
      queryClient.invalidateQueries({ queryKey: ['buyer-dashboard'] })
    },
  })
  const removePicture = useMutation({
    mutationFn: async () => (await api.delete('/buyer/profile/picture')).data,
    onSuccess: (updatedUser) => {
      updateSessionUser({ profile_picture: updatedUser.profile_picture })
      queryClient.invalidateQueries({ queryKey: ['buyer-dashboard'] })
    },
  })

  return (
    <div className="profile-page">
      <ProfileHeader
        name={saved.name || 'Your profile'}
        email={saved.email}
        badges={<RoleBadge role="buyer" />}
        meta={[
          user.created_at && [CalendarDays, `Member since ${formatMonthYear(user.created_at)}`],
        ]}
        picture={user.profile_picture}
        pictureUploading={uploadPicture.isPending || removePicture.isPending}
        onPictureUpload={(file) => uploadPicture.mutate(file)}
        onPictureRemove={() => removePicture.mutate()}
        pictureError={uploadPicture.error?.response?.data?.message || removePicture.error?.response?.data?.message}
      />

      <ProfileCard icon={UserRound} title="Personal information" description="Your name and contact details, shared with sellers you order from.">
        <div className="profile-fields">
          <ProfileField label="Full name">
            <input value={form.name} onChange={setField('name')} autoComplete="name" />
          </ProfileField>
          <ProfileField label="Email address" hint="Used to log in and to receive receipts and updates.">
            <input type="email" value={form.email} onChange={setField('email')} autoComplete="email" />
          </ProfileField>
          <ProfileField label="Phone number" hint="Optional. Helps sellers coordinate delivery.">
            <input type="tel" value={form.phone} onChange={setField('phone')} autoComplete="tel" placeholder="e.g. 0917 123 4567" />
          </ProfileField>
        </div>
        <PasswordResetNote />
      </ProfileCard>

      <ProfileCard icon={MapPin} title="Farm & delivery" description="Where your fingerlings go, and a little about your farm.">
        <div className="profile-fields">
          <ProfileField label="Address" wide>
            <input value={form.address} onChange={setField('address')} autoComplete="street-address" placeholder="Barangay, municipality, province" />
          </ProfileField>
          <ProfileField label="About you" hint="Optional. What you farm, your pond size, or anything sellers should know." wide>
            <textarea value={form.bio} onChange={setField('bio')} rows={4} />
          </ProfileField>
        </div>
      </ProfileCard>

      <ProfileSaveBar
        dirty={dirty}
        saving={saving}
        success={success}
        error={error}
        onDiscard={() => setForm(saved)}
        onSave={() => onSave(form, { onSuccess: () => setSaved(form) })}
      />
    </div>
  )
}

/**
 * Buyers, Sellers and LGU Admins change their password through the public
 * Forgot password flow (emailed link -> /reset-password), not from their
 * profile; only the Super Admin keeps an in-profile Change Password form.
 */
export function PasswordResetNote() {
  return (
    <p className="helper-text profile-password-note">
      <KeyRound size={14} aria-hidden="true" />
      Need a new password? Log out and choose <strong>Forgot password?</strong> on the login page, and we&apos;ll email you a reset link.
    </p>
  )
}

function ChangePasswordForm() {
  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [localError, setLocalError] = useState('')
  const [showPasswords, setShowPasswords] = useState(false)
  const inputType = showPasswords ? 'text' : 'password'

  const changePassword = useMutation({
    mutationFn: async () => (await api.patch('/auth/password', { current_password: currentPassword, password: newPassword })).data,
    onSuccess: () => {
      setCurrentPassword('')
      setNewPassword('')
      setConfirmPassword('')
      setLocalError('')
    },
  })

  const submit = () => {
    const pwError = validatePassword(newPassword)
    if (pwError) {
      setLocalError(pwError)
      return
    }
    if (newPassword !== confirmPassword) {
      setLocalError('New password and confirmation do not match.')
      return
    }
    setLocalError('')
    changePassword.mutate()
  }

  return (
    <div className="profile-password">
      <div className="profile-fields">
        <ProfileField label="Current password" hint="Forgot it? Log out and use Forgot password on the login page." wide>
          <input type={inputType} value={currentPassword} onChange={(e) => setCurrentPassword(e.target.value)} autoComplete="current-password" />
        </ProfileField>
        <ProfileField label="New password">
          <input type={inputType} value={newPassword} onChange={(e) => setNewPassword(stripSpaces(e.target.value))} onKeyDown={blockSpaceKey} autoComplete="new-password" />
        </ProfileField>
        <ProfileField label="Confirm new password">
          <input type={inputType} value={confirmPassword} onChange={(e) => setConfirmPassword(stripSpaces(e.target.value))} onKeyDown={blockSpaceKey} autoComplete="new-password" />
        </ProfileField>
      </div>
      <PasswordChecklist password={newPassword} confirmation={confirmPassword} />
      <div className="profile-actions">
        <PasswordVisibilityToggle shown={showPasswords} onToggle={() => setShowPasswords(!showPasswords)} />
        <button type="button" onClick={submit} disabled={changePassword.isPending || !currentPassword || !newPassword}>
          {changePassword.isPending ? 'Updating...' : 'Update password'}
        </button>
      </div>
      {localError && <p className="error">{localError}</p>}
      {changePassword.isSuccess && <p className="profile-saved" role="status"><Check size={15} aria-hidden="true" />Password updated.</p>}
      {changePassword.error && <p className="error">{changePassword.error.response?.data?.message || 'Could not update password.'}</p>}
    </div>
  )
}

/**
 * Profile tab for LGU Admins and the Super Admin. They have no public profile
 * and no endpoint to edit their own name or email, so this is the profile
 * header (photo via the per-role /profile/picture endpoints; endpointBase is
 * '/lgu' or '/super-admin'), a read-only account summary, and Change Password.
 */
export function AdminProfilePanel({ endpointBase }) {
  const session = getSession()
  // The stored session only carries municipality_id, so resolve the name from
  // the public municipality list (the same cached query registration uses).
  const municipalitiesQuery = useQuery({
    queryKey: ['municipalities'],
    queryFn: async () => (await api.get('/municipalities')).data,
    enabled: session?.role === 'lgu_admin',
    retry: false,
    placeholderData: [],
  })
  const municipalityName = (municipalitiesQuery.data || []).find((m) => m.id === session?.municipality_id)?.name
  const [picture, setPicture] = useState(session?.profile_picture || null)

  const uploadPicture = useMutation({
    mutationFn: async (file) => {
      const formData = new FormData()
      formData.append('photo', file)
      return (await api.post(`${endpointBase}/profile/picture`, formData)).data
    },
    onSuccess: (updatedUser) => {
      setPicture(updatedUser.profile_picture)
      updateSessionUser({ profile_picture: updatedUser.profile_picture })
    },
  })
  const removePicture = useMutation({
    mutationFn: async () => (await api.delete(`${endpointBase}/profile/picture`)).data,
    onSuccess: (updatedUser) => {
      setPicture(updatedUser.profile_picture)
      updateSessionUser({ profile_picture: updatedUser.profile_picture })
    },
  })

  const isLgu = session?.role === 'lgu_admin'
  const municipality = municipalityName
    || (typeof session?.municipality === 'string' ? session.municipality : session?.municipality?.name)

  return (
    <div className="profile-page">
      <ProfileHeader
        name={session?.name || 'Your profile'}
        email={session?.email}
        badges={<RoleBadge role={session?.role} />}
        meta={[
          isLgu && municipality && [MapPin, municipality],
          !isLgu && [ShieldCheck, 'Platform-wide access'],
        ]}
        picture={picture}
        pictureUploading={uploadPicture.isPending || removePicture.isPending}
        onPictureUpload={(file) => uploadPicture.mutate(file)}
        onPictureRemove={() => removePicture.mutate()}
        pictureError={uploadPicture.error?.response?.data?.message || removePicture.error?.response?.data?.message}
      />

      <ProfileCard
        icon={UserRound}
        title="Account details"
        description={isLgu
          ? 'Your name and email are managed by the Super Admin. Ask them if something needs to change.'
          : 'This administrator account is managed at the platform level.'}
      >
        <dl className="profile-details">
          <div><dt>Full name</dt><dd>{session?.name || '-'}</dd></div>
          <div><dt>Email address</dt><dd>{session?.email || '-'}</dd></div>
          <div><dt>Role</dt><dd>{roleLabel(session?.role) || '-'}</dd></div>
          <div><dt>{isLgu ? 'Municipality' : 'Scope'}</dt><dd>{isLgu ? (municipality || '-') : 'All municipalities'}</dd></div>
        </dl>
        {isLgu && <PasswordResetNote />}
      </ProfileCard>

      {session?.role === 'super_admin' && (
        <ProfileCard icon={KeyRound} title="Password & security" description="Change the password you use to log in.">
          <ChangePasswordForm />
        </ProfileCard>
      )}
    </div>
  )
}