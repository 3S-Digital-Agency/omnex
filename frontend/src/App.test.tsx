import { render, screen } from '@testing-library/react';
import { MemoryRouter, Outlet, Route, Routes } from 'react-router-dom';
import { RequireAuth } from './App';
import { useAuth } from './app/AuthProvider';

vi.mock('./app/AuthProvider', () => ({
  useAuth: vi.fn(),
}));

const mockedUseAuth = vi.mocked(useAuth);

function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route
          element={
            <RequireAuth>
              <Outlet />
            </RequireAuth>
          }
        >
          <Route path="/overview" element={<div>overview</div>} />
          <Route path="/organizations" element={<div>organizations</div>} />
        </Route>
      </Routes>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  mockedUseAuth.mockReset();
});

it('redirects /overview to /organizations when no organization is active', () => {
  mockedUseAuth.mockReturnValue({ status: 'authenticated', activeOrganization: null } as never);

  renderAt('/overview');

  expect(screen.getByText('organizations')).toBeInTheDocument();
  expect(screen.queryByText('overview')).not.toBeInTheDocument();
});

it('renders /overview when an organization is active', () => {
  mockedUseAuth.mockReturnValue({
    status: 'authenticated',
    activeOrganization: { id: 'org-1', name: 'Acme' },
  } as never);

  renderAt('/overview');

  expect(screen.getByText('overview')).toBeInTheDocument();
});

it('does not redirect when already on /organizations without an organization', () => {
  mockedUseAuth.mockReturnValue({ status: 'authenticated', activeOrganization: null } as never);

  renderAt('/organizations');

  expect(screen.getByText('organizations')).toBeInTheDocument();
});
