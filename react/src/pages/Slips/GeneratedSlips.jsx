import React, { useState, useEffect, useMemo, useCallback } from "react";
import {
  Container,
  Grid,
  Paper,
  Typography,
  Box,
  Chip,
  Button,
  Stack,
  Divider,
  TextField,
  Select,
  MenuItem,
  FormControl,
  InputLabel,
  IconButton,
  InputAdornment,
  Card,
  CardContent,
  LinearProgress,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Pagination,
  alpha,
  useTheme,
} from "@mui/material";
import {
  Search,
  FilterList,
  Sort,
  TrendingUp,
  AttachMoney,
  Warning,
  Sports,
  ArrowBack,
  Refresh,
  Visibility,
  LocalFireDepartment,
  Security,
  TrendingDown,
  Calculate,
  BarChart,
  ExpandMore,
  ExpandLess,
} from "@mui/icons-material";
import { useParams, useNavigate } from "react-router-dom";
import slipApi from "../../services/api/slipApi";
import { theme as customTheme } from "../../theme";
import SlipDetailModal from "../../components/matches/SlipDetailModal";

// Constants
const PAGE_SIZE = 15;
const RISK_COLORS = {
  High: customTheme.colors.accent.error,
  Medium: customTheme.colors.accent.warning,
  Low: customTheme.colors.accent.success,
};

const GeneratedSlips = () => {
  const { masterSlipId } = useParams();
  const navigate = useNavigate();
  const [state, setState] = useState({
    loading: true,
    error: null,
    masterSlip: null,
    slips: [],
    filteredSlips: [],
    paginatedSlips: [],
    stats: null,
    // expandedSlipId: null,
  });

  // ADD THIS: Modal state
  const [modalState, setModalState] = useState({
    open: false,
    selectedSlip: null,
  });

  // Handlers for the modal
  const handleOpenModal = (slip) => {
    setModalState({
      open: true,
      selectedSlip: slip,
    });
  };

  const handleCloseModal = () => {
    setModalState({
      open: false,
      selectedSlip: null,
    });
  };

  // UI State
  const [filters, setFilters] = useState({
    search: "",
    riskLevel: "all",
    minConfidence: 0,
    maxOdds: 1000,
  });
  const [sortBy, setSortBy] = useState("confidence");
  const [currentPage, setCurrentPage] = useState(1);

  // Fetch data
  useEffect(() => {
    const fetchData = async () => {
      try {
        setState((prev) => ({ ...prev, loading: true, error: null }));
        const response = await slipApi.getGeneratedSlips(masterSlipId);
        const { master_slip, generated_slips, summary } = response.data;

        // Calculate correct financials
        const totalSlips = generated_slips.length;

        const processedSlips = generated_slips.map((slip) => ({
          ...slip,
          calculated_stake: slip.stake, // ✅ Use database stake
          calculated_return: slip.stake * slip.total_odds, // ✅ Use actual stake
          confidence_percentage: (slip.confidence_score * 100).toFixed(1),
        }));

        // Calculate total from individual slips (not master stake)
        const totalCalculatedStake = processedSlips.reduce(
          (sum, slip) => sum + slip.calculated_stake,
          0
        );

        // Calculate statistics using actual slip data
        const stats = {
          totalSlips,
          totalInvestment: totalCalculatedStake, // Sum of individual stakes
          averageConfidence: summary.average_confidence * 100 || 0,
          averageOdds:
            processedSlips.reduce((sum, slip) => sum + slip.total_odds, 0) /
            totalSlips,
          averageStake: totalCalculatedStake / totalSlips, // Average of individual stakes
          highestReturn: Math.max(
            ...processedSlips.map((s) => s.calculated_return)
          ),
          highestConfidence: Math.max(
            ...processedSlips.map((s) => s.confidence_score * 100)
          ),
          riskDistribution: summary.risk_distribution || {
            High: 0,
            Medium: 0,
            Low: 0,
          },
        };

        // Optional: Validate stake consistency
        if (Math.abs(totalCalculatedStake - master_slip.stake) > 0.01) {
          console.warn(
            `Stake mismatch: Slips total ${totalCalculatedStake}, Master total ${master_slip.stake}`
          );
        }

        setState({
          loading: false,
          error: null,
          masterSlip: master_slip,
          slips: processedSlips,
          filteredSlips: processedSlips,
          paginatedSlips: processedSlips.slice(0, PAGE_SIZE),
          stats,
          expandedSlipId: null,
        });
      } catch (error) {
        setState((prev) => ({
          ...prev,
          loading: false,
          error: "Failed to load generated slips. Please try again.",
        }));
        console.error("Error fetching slips:", error);
      }
    };

    fetchData();
  }, [masterSlipId]);

  // Apply filters and sorting
  useEffect(() => {
    if (!state.slips.length) return;

    let filtered = [...state.slips];

    // Apply search filter
    if (filters.search) {
      const searchLower = filters.search.toLowerCase();
      filtered = filtered.filter(
        (slip) =>
          slip.slip_id.toLowerCase().includes(searchLower) ||
          slip.legs.some(
            (leg) =>
              leg.match_id.toLowerCase().includes(searchLower) ||
              leg.selection.toLowerCase().includes(searchLower)
          )
      );
    }

    // Apply risk filter
    if (filters.riskLevel !== "all") {
      filtered = filtered.filter(
        (slip) =>
          slip.risk_level.toLowerCase() === filters.riskLevel.toLowerCase()
      );
    }

    // Apply confidence filter
    filtered = filtered.filter(
      (slip) => slip.confidence_score * 100 >= filters.minConfidence
    );

    // Apply odds filter
    filtered = filtered.filter((slip) => slip.total_odds <= filters.maxOdds);

    // Apply sorting
    filtered.sort((a, b) => {
      switch (sortBy) {
        case "confidence":
          return b.confidence_score - a.confidence_score;
        case "odds":
          return b.total_odds - a.total_odds;
        case "return":
          return b.calculated_return - a.calculated_return;
        case "stake":
          return b.calculated_stake - a.calculated_stake;
        case "risk":
          const riskOrder = { High: 3, Medium: 2, Low: 1 };
          return riskOrder[b.risk_level] - riskOrder[a.risk_level];
        default:
          return 0;
      }
    });

    // Update pagination
    const startIndex = (currentPage - 1) * PAGE_SIZE;
    const paginated = filtered.slice(startIndex, startIndex + PAGE_SIZE);

    setState((prev) => ({
      ...prev,
      filteredSlips: filtered,
      paginatedSlips: paginated,
    }));
  }, [state.slips, filters, sortBy, currentPage]);

  // Handlers
  const handlePageChange = (event, page) => {
    setCurrentPage(page);
  };

  const handleSearchChange = (event) => {
    setFilters((prev) => ({ ...prev, search: event.target.value }));
    setCurrentPage(1);
  };

  const handleRiskFilterChange = (event) => {
    setFilters((prev) => ({ ...prev, riskLevel: event.target.value }));
    setCurrentPage(1);
  };

  const handleSortChange = (event) => {
    setSortBy(event.target.value);
  };

  const handleRefresh = async () => {
    // Re-fetch data
    try {
      setState((prev) => ({ ...prev, loading: true }));
      const response = await slipApi.getGeneratedSlips(masterSlipId);
      const { master_slip, generated_slips } = response.data;

      const totalSlips = generated_slips.length;
      const stakePerSlip = master_slip.stake / totalSlips;

      const processedSlips = generated_slips.map((slip) => ({
        ...slip,
        calculated_stake: stakePerSlip,
        calculated_return: stakePerSlip * slip.total_odds,
        confidence_percentage: (slip.confidence_score * 100).toFixed(1),
      }));

      setState((prev) => ({
        ...prev,
        loading: false,
        masterSlip: master_slip,
        slips: processedSlips,
        filteredSlips: processedSlips,
      }));
    } catch (error) {
      setState((prev) => ({
        ...prev,
        loading: false,
        error: "Failed to refresh data",
      }));
    }
  };



  // Format currency
  const formatCurrency = (amount) => {
    return new Intl.NumberFormat("en-US", {
      style: "currency",
      currency: state.masterSlip?.currency || "EUR",
      minimumFractionDigits: 2,
    }).format(amount);
  };

  // Loading state
  if (state.loading) {
    return (
      <Container maxWidth="xl" sx={{ py: 8 }}>
        <Box
          display="flex"
          justifyContent="center"
          alignItems="center"
          minHeight="60vh"
        >
          <Typography variant="h6" color="text.secondary">
            Loading slips data...
          </Typography>
        </Box>
      </Container>
    );
  }

  // Error state
  if (state.error) {
    return (
      <Container maxWidth="xl" sx={{ py: 8 }}>
        <Paper sx={{ p: 4, textAlign: "center" }}>
          <Typography variant="h6" color="error" gutterBottom>
            {state.error}
          </Typography>
          <Button variant="contained" onClick={handleRefresh}>
            Retry
          </Button>
        </Paper>
      </Container>
    );
  }

  // No data state
  if (!state.slips.length) {
    return (
      <Container maxWidth="xl" sx={{ py: 8 }}>
        <Paper sx={{ p: 4, textAlign: "center" }}>
          <Typography variant="h6" gutterBottom>
            No generated slips found
          </Typography>
          <Button variant="outlined" onClick={() => navigate(-1)}>
            Go Back
          </Button>
        </Paper>
      </Container>
    );
  }

  return (
    <Container maxWidth="xl" sx={{ py: 3 }}>
      {/* Header */}
      <Paper
        sx={{
          p: 3,
          mb: 3,
          background: `linear-gradient(135deg, ${alpha(
            customTheme.colors.surface.card,
            0.9
          )} 0%, ${alpha(customTheme.colors.background.primary, 0.95)} 100%)`,
          border: `1px solid ${alpha(customTheme.colors.border.light, 0.1)}`,
          borderRadius: 2,
        }}
      >
        <Stack
          direction="row"
          justifyContent="space-between"
          alignItems="center"
          mb={2}
        >
          <Box>
            <Typography
              variant="h4"
              fontWeight={700}
              sx={{
                background: `linear-gradient(135deg, ${customTheme.colors.accent.primary} 0%, ${customTheme.colors.accent.secondary} 100%)`,
                WebkitBackgroundClip: "text",
                WebkitTextFillColor: "transparent",
                mb: 1,
              }}
            >
              Generated Slips Analysis
            </Typography>
            <Stack direction="row" spacing={2} alignItems="center">
              <Chip
                label={`Master: #${masterSlipId}`}
                size="small"
                sx={{
                  background: alpha(customTheme.colors.accent.primary, 0.1),
                  color: customTheme.colors.accent.primary,
                }}
              />
              <Typography variant="body2" color="text.secondary">
                {state.slips.length} slips generated
              </Typography>
              <Typography variant="body2" color="text.secondary">
                Total stake: {formatCurrency(state.masterSlip?.stake || 0)}
              </Typography>
            </Stack>
          </Box>
          <Stack direction="row" spacing={1}>
            <Button
              variant="outlined"
              startIcon={<ArrowBack />}
              onClick={() => navigate(-1)}
              sx={{
                borderColor: alpha(customTheme.colors.border.light, 0.3),
              }}
            >
              Back
            </Button>
            <Button
              variant="contained"
              startIcon={<Refresh />}
              onClick={handleRefresh}
              sx={{
                background: `linear-gradient(135deg, ${customTheme.colors.accent.primary} 0%, ${customTheme.colors.accent.primaryHover} 100%)`,
              }}
            >
              Refresh
            </Button>
          </Stack>
        </Stack>
      </Paper>

      {/* Statistics Dashboard */}
      {state.stats && (
        <Grid container spacing={2} sx={{ mb: 3 }}>
          <Grid item xs={12} sm={6} md={2.4}>
            <Card
              sx={{
                height: "100%",
                background: alpha(customTheme.colors.surface.card, 0.8),
                border: `1px solid ${alpha(customTheme.colors.border.light, 0.1)}`,
              }}
            >
              <CardContent>
                <Typography
                  variant="subtitle2"
                  color="text.secondary"
                  gutterBottom
                >
                  Total Slips
                </Typography>
                <Typography variant="h4" fontWeight={700}>
                  {state.stats.totalSlips}
                </Typography>
              </CardContent>
            </Card>
          </Grid>
          <Grid item xs={12} sm={6} md={2.4}>
            <Card
              sx={{
                height: "100%",
                background: alpha(customTheme.colors.surface.card, 0.8),
                border: `1px solid ${alpha(customTheme.colors.border.light, 0.1)}`,
              }}
            >
              <CardContent>
                <Typography
                  variant="subtitle2"
                  color="text.secondary"
                  gutterBottom
                >
                  Avg Confidence
                </Typography>
                <Typography
                  variant="h4"
                  fontWeight={700}
                  color={customTheme.colors.accent.success}
                >
                  {state.stats.averageConfidence.toFixed(1)}%
                </Typography>
              </CardContent>
            </Card>
          </Grid>
          <Grid item xs={12} sm={6} md={2.4}>
            <Card
              sx={{
                height: "100%",
                background: alpha(customTheme.colors.surface.card, 0.8),
                border: `1px solid ${alpha(customTheme.colors.border.light, 0.1)}`,
              }}
            >
              <CardContent>
                <Typography
                  variant="subtitle2"
                  color="text.secondary"
                  gutterBottom
                >
                  Avg Odds
                </Typography>
                <Typography variant="h4" fontWeight={700}>
                  {state.stats.averageOdds.toFixed(2)}×
                </Typography>
              </CardContent>
            </Card>
          </Grid>
          <Grid item xs={12} sm={6} md={2.4}>
            <Card
              sx={{
                height: "100%",
                background: alpha(customTheme.colors.surface.card, 0.8),
                border: `1px solid ${alpha(customTheme.colors.border.light, 0.1)}`,
              }}
            >
              <CardContent>
                <Typography
                  variant="subtitle2"
                  color="text.secondary"
                  gutterBottom
                >
                  Max Return
                </Typography>
                <Typography
                  variant="h4"
                  fontWeight={700}
                  color={customTheme.colors.accent.success}
                >
                  {formatCurrency(state.stats.highestReturn)}
                </Typography>
              </CardContent>
            </Card>
          </Grid>
          <Grid item xs={12} sm={6} md={2.4}>
            <Card
              sx={{
                height: "100%",
                background: alpha(customTheme.colors.surface.card, 0.8),
                border: `1px solid ${alpha(customTheme.colors.border.light, 0.1)}`,
              }}
            >
              <CardContent>
                <Typography
                  variant="subtitle2"
                  color="text.secondary"
                  gutterBottom
                >
                  Avg Stake/Slip
                </Typography>
                <Typography variant="h4" fontWeight={700}>
                  {formatCurrency(state.stats.averageStake || 0)}
                </Typography>
              </CardContent>
            </Card>
          </Grid>
        </Grid>
      )}

      {/* Filters & Controls */}
      <Paper
        sx={{
          p: 2,
          mb: 3,
          background: alpha(customTheme.colors.surface.card, 0.9),
          border: `1px solid ${alpha(customTheme.colors.border.light, 0.1)}`,
          borderRadius: 2,
        }}
      >
        <Grid container spacing={2} alignItems="center">
          <Grid item xs={12} md={4}>
            <TextField
              fullWidth
              size="small"
              placeholder="Search slips or matches..."
              value={filters.search}
              onChange={handleSearchChange}
              InputProps={{
                startAdornment: (
                  <InputAdornment position="start">
                    <Search />
                  </InputAdornment>
                ),
                sx: {
                  background: alpha(customTheme.colors.background.primary, 0.7),
                },
              }}
            />
          </Grid>
          <Grid item xs={12} md={2}>
            <FormControl fullWidth size="small">
              <InputLabel>Risk Level</InputLabel>
              <Select
                value={filters.riskLevel}
                label="Risk Level"
                onChange={handleRiskFilterChange}
              >
                <MenuItem value="all">All Risks</MenuItem>
                <MenuItem value="high">High Risk</MenuItem>
                <MenuItem value="medium">Medium Risk</MenuItem>
                <MenuItem value="low">Low Risk</MenuItem>
              </Select>
            </FormControl>
          </Grid>
          <Grid item xs={12} md={2}>
            <FormControl fullWidth size="small">
              <InputLabel>Sort By</InputLabel>
              <Select
                value={sortBy}
                label="Sort By"
                onChange={handleSortChange}
              >
                <MenuItem value="confidence">Confidence</MenuItem>
                <MenuItem value="odds">Total Odds</MenuItem>
                <MenuItem value="return">Potential Return</MenuItem>
                <MenuItem value="stake">Stake Amount</MenuItem>
                <MenuItem value="risk">Risk Level</MenuItem>
              </Select>
            </FormControl>
          </Grid>
          <Grid item xs={12} md={2}>
            <TextField
              fullWidth
              size="small"
              type="number"
              label="Min Confidence %"
              value={filters.minConfidence}
              onChange={(e) =>
                setFilters((prev) => ({
                  ...prev,
                  minConfidence: Math.max(
                    0,
                    Math.min(100, Number(e.target.value))
                  ),
                }))
              }
              InputProps={{
                endAdornment: <InputAdornment position="end">%</InputAdornment>,
              }}
            />
          </Grid>
          <Grid item xs={12} md={2}>
            <TextField
              fullWidth
              size="small"
              type="number"
              label="Max Odds"
              value={filters.maxOdds}
              onChange={(e) =>
                setFilters((prev) => ({
                  ...prev,
                  maxOdds: Math.max(1, Number(e.target.value)),
                }))
              }
            />
          </Grid>
        </Grid>
      </Paper>

      {/* Results Summary */}
      <Box
        display="flex"
        justifyContent="space-between"
        alignItems="center"
        mb={2}
      >
        <Typography variant="subtitle1">
          Showing {state.paginatedSlips.length} of {state.filteredSlips.length}{" "}
          slips
          {filters.search && ` for "${filters.search}"`}
        </Typography>
        <Typography variant="body2" color="text.secondary">
          Page {currentPage} of{" "}
          {Math.ceil(state.filteredSlips.length / PAGE_SIZE)}
        </Typography>
      </Box>

      {/* Slips Grid */}
      <Grid container spacing={2}>
        {state.paginatedSlips.map((slip) => (
          <Grid item xs={12} key={slip.slip_id}>
            <Card
              sx={{
                background: alpha(customTheme.colors.surface.card, 0.8),
                border: `1px solid ${alpha(customTheme.colors.border.light, 0.1)}`,
                "&:hover": {
                  borderColor: alpha(customTheme.colors.accent.primary, 0.3),
                },
              }}
            >
              <CardContent>
                {/* Slip Header */}
                <Stack
                  direction="row"
                  justifyContent="space-between"
                  alignItems="center"
                  mb={2}
                >
                  <Box>
                    <Typography variant="h6" fontWeight={600}>
                      {slip.slip_id}
                    </Typography>
                    <Typography variant="caption" color="text.secondary">
                      {new Date(slip.created_at).toLocaleDateString()} •{" "}
                      {slip.legs_count} legs
                    </Typography>
                  </Box>
                  <Stack direction="row" spacing={1} alignItems="center">
                    <Chip
                      label={slip.risk_level}
                      size="small"
                      sx={{
                        background: alpha(
                          RISK_COLORS[slip.risk_level] ||
                            customTheme.colors.accent.warning,
                          0.15
                        ),
                        color:
                          RISK_COLORS[slip.risk_level] ||
                          customTheme.colors.accent.warning,
                        fontWeight: 600,
                      }}
                    />
                    <Button
                      variant="outlined"
                      size="small"
                      onClick={() => handleOpenModal(slip)}
                      startIcon={<Visibility />}
                      sx={{
                        borderColor: alpha(customTheme.colors.border.light, 0.3),
                        color: customTheme.colors.text.secondary,
                        '&:hover': {
                          borderColor: customTheme.colors.accent.primary,
                          color: customTheme.colors.accent.primary,
                        },
                      }}
                    >
                      Details
                    </Button>
                  </Stack>
                </Stack>

                {/* Key Metrics */}
                <Grid container spacing={2} mb={2}>
                  <Grid item xs={12} sm={6} md={3}>
                    <Box>
                      <Typography variant="caption" color="text.secondary">
                        Confidence
                      </Typography>
                      <Box display="flex" alignItems="center" gap={1}>
                        <LinearProgress
                          variant="determinate"
                          value={slip.confidence_score * 100}
                          sx={{
                            flex: 1,
                            height: 6,
                            borderRadius: 1,
                            backgroundColor: alpha(
                              customTheme.colors.background.tertiary,
                              0.5
                            ),
                            "& .MuiLinearProgress-bar": {
                              backgroundColor:
                                slip.confidence_score >= 0.7
                                  ? customTheme.colors.accent.success
                                  : slip.confidence_score >= 0.4
                                    ? customTheme.colors.accent.warning
                                    : customTheme.colors.accent.error,
                            },
                          }}
                        />
                        <Typography variant="body2" fontWeight={600}>
                          {slip.confidence_percentage}%
                        </Typography>
                      </Box>
                    </Box>
                  </Grid>
                  <Grid item xs={12} sm={6} md={3}>
                    <Box>
                      <Typography variant="caption" color="text.secondary">
                        Stake
                      </Typography>
                      <Typography variant="body1" fontWeight={600}>
                        {formatCurrency(slip.calculated_stake)}
                      </Typography>
                    </Box>
                  </Grid>
                  <Grid item xs={12} sm={6} md={3}>
                    <Box>
                      <Typography variant="caption" color="text.secondary">
                        Total Odds
                      </Typography>
                      <Typography
                        variant="body1"
                        fontWeight={600}
                        color={customTheme.colors.accent.primary}
                      >
                        {slip.total_odds.toFixed(2)}×
                      </Typography>
                    </Box>
                  </Grid>
                  <Grid item xs={12} sm={6} md={3}>
                    <Box>
                      <Typography variant="caption" color="text.secondary">
                        Potential Return
                      </Typography>
                      <Typography
                        variant="body1"
                        fontWeight={600}
                        color={customTheme.colors.accent.success}
                      >
                        {formatCurrency(slip.calculated_return)}
                      </Typography>
                    </Box>
                  </Grid>
                </Grid>

      
              </CardContent>
            </Card>
          </Grid>
        ))}
      </Grid>

      {/* Pagination */}
      {state.filteredSlips.length > PAGE_SIZE && (
        <Box display="flex" justifyContent="center" mt={3}>
          <Pagination
            count={Math.ceil(state.filteredSlips.length / PAGE_SIZE)}
            page={currentPage}
            onChange={handlePageChange}
            color="primary"
            sx={{
              "& .MuiPaginationItem-root": {
                color: customTheme.colors.text.primary,
                "&.Mui-selected": {
                  background: `linear-gradient(135deg, ${customTheme.colors.accent.primary} 0%, ${customTheme.colors.accent.primaryHover} 100%)`,
                  color: "white",
                },
              },
            }}
          />
        </Box>
      )}

      {modalState.selectedSlip && (
        <SlipDetailModal
          open={modalState.open}
          onClose={handleCloseModal}
          slip={modalState.selectedSlip}
        />
      )}

      {/* Footer Stats */}
      <Paper
        sx={{
          p: 2,
          mt: 3,
          background: alpha(customTheme.colors.surface.card, 0.8),
          border: `1px solid ${alpha(customTheme.colors.border.light, 0.1)}`,
          borderRadius: 2,
        }}
      >
        <Typography variant="caption" color="text.secondary">
          Showing {state.filteredSlips.length} filtered slips • Total
          investment: {formatCurrency(state.masterSlip?.stake || 0)} • Average
          confidence: {state.stats?.averageConfidence.toFixed(1)}%
        </Typography>
      </Paper>
    </Container>
  );
};;

export default GeneratedSlips;