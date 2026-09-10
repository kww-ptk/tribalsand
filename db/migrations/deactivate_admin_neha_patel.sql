-- One-off: deactivate the admin login neha.patel@tribalsand.com.
-- Run once from Admin → Migrations (browser, owner-authenticated) when CloudShell
-- is unavailable. Deactivate (reversible) rather than delete, so audit history is
-- kept and the login simply stops working.
--
-- SAFETY: refuses to deactivate the only active owner (raises an error instead),
-- so nobody can be locked out. If the account doesn't exist, it errors clearly.
-- Idempotent-ish: running again after it's already inactive just re-sets FALSE.
--
-- After it has been run successfully, this file can be deleted from the repo.

DO $$
DECLARE
  v_id           int;
  v_role         text;
  v_other_owners int;
BEGIN
  SELECT id, role INTO v_id, v_role
    FROM admin_users
   WHERE lower(email) = 'neha.patel@tribalsand.com';

  IF v_id IS NULL THEN
    RAISE EXCEPTION 'No admin account has that email — nothing to deactivate.';
  END IF;

  IF v_role = 'owner' THEN
    SELECT COUNT(*) INTO v_other_owners
      FROM admin_users
     WHERE role = 'owner' AND is_active = TRUE AND id <> v_id;
    IF v_other_owners = 0 THEN
      RAISE EXCEPTION 'Refused: this is the only active owner. Deactivating it would lock everyone out.';
    END IF;
  END IF;

  UPDATE admin_users SET is_active = FALSE WHERE id = v_id;
  RAISE NOTICE 'Deactivated admin account id=% role=% (neha.patel@tribalsand.com).', v_id, v_role;
END $$;
